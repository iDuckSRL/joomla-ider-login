<?php

/**
 * iDuck SRL
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the EULA
 * that is bundled with this package in the file LICENSE.txt.
 *
 ********************************************************************
 * @category     iDuckSRL
 * @package      Joomla.Plugin
 * @subpackage   System.IDer_Login
 * @author       Emanuele Coppola <plugins@ider.com>
 * @copyright    Copyright (c) 2016 - 2024 iDuck SRL
 * @license      https://github.com/iDuckSRL/joomla-ider-login
 */

defined('_JEXEC') or die;

use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Log\Log;
use Joomla\CMS\User\User;
use Joomla\CMS\Factory;
use Joomla\Event\Event;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Registry\Registry;
use Joomla\CMS\String\PunycodeHelper;

class IDER_Callback
{
    static function handler($userInfo)
    {
        $userInfo = IDER_UserInfoManager::normalize($userInfo);

        $app = Factory::getApplication();
        $dispatcher = $app->getDispatcher();

        $event = new Event(
            'onIDerBeforeCallbackHandler',
            array(
                'userInfo' => $userInfo,
                'openid_connect_scope' => $_SESSION['openid_connect_scope']
            )
        );

        $dispatcher->dispatch($event->getName(), $event);

        if (!$event->getArgument('result', false)) {
            self::defaultHandler($userInfo);
        }
    }

    // register or authenticate user
    static function defaultHandler($userInfo)
    {
        /** @var CMSApplication $app */
        $app = Factory::getApplication();
        $dispatcher = $app->getDispatcher();

        // check if user exists by email
        $userID = self::getUserIdBy('email', $userInfo->email);

        if(empty($userID)){
            // check if user exists by sub
            $userID = self::getUserIdBySub($userInfo->sub);
        }

        if(empty($userID)) {
            $userID = self::_do_register($userInfo);
        }

        $user = Factory::getUser($userID);

        if($user->guest) {
            self::_delete_ider_data($userID);

            $userID = self::_do_register($userInfo);
            $user = Factory::getUser($userID);
        }

        // check for email changes
        if($user->email !== $userInfo->email){
            if(self::_local_mail_identical($user->id, $user->email)){
                self::_update_user_mail($userID, $userInfo->email);
            } else {
                self::user_logout();

                self::access_denied('Update the IDer email first!');
                
                /** @var CMSApplication $app */
                $app->redirect('/');
            }
        }

        // Log the User In
        self::_login($userID);

        self::_update_ider_table($userID, $userInfo);

        $user = Factory::getUser();

        if(!$user->guest) {
            $event = new Event(
                'onIDerAfterCallbackHandler',
                array(
                    'userInfo' => $userInfo,
                    'openid_connect_scope' => $_SESSION['openid_connect_scope']
                )
            );

            $dispatcher->dispatch('onIDerAfterCallbackHandler', $event);

            if (!$event->getArgument('result', false)) {
                $plugin = PluginHelper::getPlugin('system', 'ider_login');
                $pluginParams = new Registry($plugin->params);
    
                $iderRedirectUri = $pluginParams->get('ider_redirect_uri');
    
                if(empty($iderRedirectUri)) {
                    $iderRedirectUri = '/';
                }
    
                /** @var CMSApplication $app */
                $app->redirect($iderRedirectUri);
            }
        }

        self::access_denied("User unable to login.");
    }

    /**
     * Show error message if the user doesn't have access.
     */
    static function access_denied($errormsg)
    {
        /** @var CMSApplication $app */
        $app = Factory::getApplication();

        if (is_null($errormsg)) {
            $errormsg = "Error authenticating user";
        }

        $app->enqueueMessage($errormsg, Log::ERROR, 'application');
    }

    /**
     * Show error message if the user doesn't have access.
     */
    static function _delete_ider_data($userID)
    {
        try {
            $db = Factory::getDbo();
            $query = $db->getQuery(true);

            // Prepare the delete query
            $conditions = array(
                $db->quoteName('uid') . ' = ' . $db->quote($userID),
            );

            $query->delete($db->quoteName('#__ider_user_data'));
            $query->where($conditions);

            $db->setQuery($query);
            $result = $db->execute();

            return true;
        } catch (\RuntimeException $e) {
            self::access_denied($e->getMessage());
        }

        return false;
    }

    /**
     * Logout the user.
     */
    static function user_logout()
    {
        $user = Factory::getUser();

        if(!$user->guest){
            // Perform logout
            $session = Factory::getSession();
            $session->expire();
            $user->logout();
            
            // Destroy session
            $session->destroy();
            $session->start();
        }
    }

    /**
     * Add a record foreach field inside IDer table
     */
    private static function _update_ider_table($userID, $userInfo)
    {
        $db = Factory::getDbo();
        $query = $db->getQuery(true);
        $query->select('*')
            ->from($db->quoteName('#__ider_user_data'))
            ->where($db->quoteName('uid') . ' = ' . (int) $userID);

        $db->setQuery($query);
        $results = $db->loadAssocList(); // Load results as an associative array

        $existingFields = [];
        foreach ($results as $result) {
            $existingFields[$result['user_field']] = $result;
        }

        foreach ($userInfo as $key => $value) {
            try {
                if (!array_key_exists($key, $existingFields)) {
                    // Insert data
                    // Create and populate an object.
                    $data = new stdClass();
                    $data->uid = $userID;
                    $data->user_field = $key;
                    $data->user_value = $value;
            
                    $result = $db->insertObject('#__ider_user_data', $data);
                } else {
                    // Update data
                    // Create an object for the record we are going to update.
                    $data = new stdClass();
                    $data->uid = $userID;
                    $data->user_field = $key;
                    $data->user_value = $value;
            
                    // Update their details in the users table using uid and user_field as primary keys.
                    $result = $db->updateObject('#__ider_user_data', $data, ['uid', 'user_field']);
                }
            } catch (\RuntimeException $e) {
                self::access_denied($e->getMessage());
            }
        }
    }

    /**
     * Register an user.
     */
    private static function _do_register($userInfo)
    {
        $plugin = PluginHelper::getPlugin('system', 'ider_login');
        $pluginParams = new Registry($plugin->params);

        // Format key=>iderdata value=>joomla field
        $fieldMapping = [
            'field' => 'corresponding field'
        ];
        
        $randomPassword = substr(md5($userInfo->email), 0, 12);

        $data = array(
            'name' => $userInfo->family_name . ' ' . $userInfo->given_name,
            'username' => $userInfo->email,
            'email' => $userInfo->email,
            'block' => $pluginParams->get('ider_register_as_enabled') ? 0 : 1,
            'activation' => $pluginParams->get('ider_register_as_activated') ? '' : PunycodeHelper::getRandom(10),
            'password' => $randomPassword
        );

        $user = new User;

        if (!$user->bind($data)) {
            return;
        }
    
        if (!$user->save()) {
            return;
        }

        // Destroy eventual login sessions
        self::user_logout();
        
        return $user->id;
    }

    /**
     * Login an user by its ID.
     */
    private static function _login($userID)
    {
        /** @var CMSApplication $app */
        $app = Factory::getApplication();
        $user = Factory::getUser();
        $session = Factory::getSession();

        if ($user->guest) {
            $user = Factory::getUser($userID);

            if (!$user->guest && $user->id) {
                $session->set('user', $user);

                $user->setLastVisit();
                $app->checkSession();
                
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the local mail are identical
     */
    private static function _local_mail_identical($userID, $userMail)
    {
        $areIdentical = false;

        try {
            $db = Factory::getDbo();
            
            $query = $db->getQuery(true)
                ->select($db->quoteName('id'))
                ->from($db->quoteName('#__ider_user_data'))
                ->where($db->quoteName('uid') . ' = ' . $db->quote($userID))
                ->where($db->quoteName('user_field') . ' = ' . $db->quote('email'))
                ->where($db->quoteName('user_value') . ' = ' . $db->quote($userMail));

            $db->setQuery($query);
            $result = $db->loadResult();

            if ($result) {
                $areIdentical = true;
            }
        } catch (\RuntimeException $e) {
            self::access_denied($e->getMessage());
        }

        return $areIdentical;
    }

    /**
     * Update the old mail with a new one.
     */
    private static function _update_user_mail($userID, $email)
    {
        try {
            $db = Factory::getDbo();

            $query = $db->getQuery(true);
            
            // Prepare the fields to be updated
            $fields = [
                $db->quoteName('email') . ' = ' . $db->quote($email)
            ];
            
            // Prepare the conditions for the update
            $conditions = [
                $db->quoteName('id') . ' = ' . $db->quote($userID)
            ];
            
            // Construct the update query
            $query->update($db->quoteName('#__users'))
                ->set($fields)
                ->where($conditions);
            
            // Set and execute the query
            $db->setQuery($query);
            $db->execute();
        } catch (\RuntimeException $e) {
            self::access_denied($e->getMessage());
        }
    }

    /**
     *  Fetch user ID by using a custom field.
     */
    private static function getUserIdBy($field, $value) {
        try {
            // Initialise some variables
            $db = Factory::getDbo();

            $query = $db->getQuery(true)
                ->select($db->quoteName('id'))
                ->from($db->quoteName('#__users'))
                ->where($db->quoteName($field) . ' = ' . $db->quote($value));

            $db->setQuery($query);
        } catch (RuntimeException $e) {
            self::access_denied($e->getMessage());
        }

        return $db->loadResult();
    }

    /**
     *  Fetch user ID by using the sub.
     */
    private static function getUserIdBySub($iderSub) {
        try {
            // Initialise some variables
            $db = Factory::getDbo();

            $query = $db->getQuery(true)
                ->select($db->quoteName('uid'))
                ->from($db->quoteName('#__ider_user_data'))
                ->where($db->quoteName('user_field') . ' = ' . $db->quote('sub'))
                ->where($db->quoteName('user_value') . ' = ' . $db->quote($iderSub));


            $result = false;
    
            $result = false;
            $db->setQuery($query);
            $result = $db->loadResult();
    
            if ($result) {
                return $result;
            }
        } catch (RuntimeException $e) {
            self::access_denied($e->getMessage());
        }
    
        return false;
    }
}
