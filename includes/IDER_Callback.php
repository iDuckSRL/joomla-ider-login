<?php

/**
 * Jlm SRL
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the EULA
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * https://www.ider.com/IDER-LICENSE-COMMUNITY.txt
 *
 ********************************************************************
 * @category     Jlmsrl
 * @package      Joomla.Plugin
 * @subpackage   System.IDer_Login
 * @author       Emanuele Coppola <plugins@jlm.srl>
 * @copyright    Copyright (c) 2016 - 2017 Jlm SRL (http://www.jlm.srl)
 * @license      https://www.ider.com/IDER-LICENSE-COMMUNITY.txt
 */

defined('_JEXEC') or die;

use Joomla\Event\Dispatcher;
use Joomla\Event\Event;

jimport('joomla.plugin.helper');


class IDER_Callback
{

    static function handler($userInfo)
    {

        // normalize the user info
        $userInfo = IDER_UserInfoManager::normalize($userInfo);

        // I'll setup the event dispatcher and I'll trigger the event
        $dispatcher = JEventDispatcher::getInstance();
        $handled = reset($dispatcher->trigger('onIDerBeforeCallbackHandler', array($userInfo, $_SESSION['openid_connect_scope'])));

        // if user function hadn't been exclusive let's resume the standard flow
        if (!$handled) {
            self::defaultHandler($userInfo);
        }

    }


    // register or authenticate user
    static function defaultHandler($userInfo)
    {

        $app = JFactory::getApplication();

        // check if user exists by email
        $userID = self::getUserIdBy('email', $userInfo->email);

        if(empty($userID)){

            // check if user exists by sub
            $userID = self::getUserIdBySub($userInfo->sub);

        }

        if(empty($userID)) {

            $userID = self::_do_register($userInfo);

        }

        $user = JFactory::getUser($userID);

        // check for email changes
        if($user->email !== $userInfo->email){

            if(self::_local_mail_identical($user->id, $user->email)){

                self::_update_user_mail($userID, $userInfo->email);

            }else{

                self::user_logout();

                self::access_denied('Update the IDer email first!');

                $app->redirect('/');

            }

        }

        // Log the User In
        self::_login($userID);

        self::_update_ider_table($userID, $userInfo);

        if(!$user->guest) {

            $app = JFactory::getApplication('site');

            // I'll setup the event dispatcher and I'll trigger the event
            $dispatcher = JEventDispatcher::getInstance();
            $handled = reset($dispatcher->trigger('onIDerAfterCallbackHandler', array($userInfo, $_SESSION['openid_connect_scope'])));

            $plugin = JPluginHelper::getPlugin('system', 'ider_login');
            $pluginParams = new JRegistry($plugin->params);

            $app->redirect($pluginParams->get('ider_redirect_uri'));

        }

        self::access_denied("User unable to login.");

    }

    /**
     * Show error message if the user doesn't have access.
     */
    static function access_denied($errormsg)
    {

        if (is_null($errormsg)) {
            $errormsg = "Error authenticating user";
        }

        JFactory::getApplication()->enqueueMessage(JText::_($errormsg), 'error');

    }

    /**
     * Logout the user
     */
    static function user_logout()
    {
        $app = JFactory::getApplication();
        $user = JFactory::getUser();

        if(!$user->guest){
            $userID = $user->get('id');
            $app->logout($userID, array());
            @session_destroy();
            @session_start();
        }

    }

    /**
     * Add a record foreach field inside IDer table
     */
    private static function _update_ider_table($userID, $userInfo)
    {

        foreach ($userInfo as $key => $value) {

            try {

                $db = JFactory::getDbo();

                $query = $db->getQuery(true);
                $query->select('*')
                    ->from($db->quoteName('#__ider_user_data'))
                    ->where($db->quoteName('uid') . ' = '. $userID)
                    ->where($db->quoteName('user_field') . ' = '. $db->quote($key));

                $db->setQuery($query);

                $result = $db->loadResult();

                if($result !== null) {
                    // Update data

                    // Create an object for the record we are going to update.
                    $data = new stdClass();

                    // Must be a valid primary key value.
                    $data->uid = $userID;
                    $data->user_field = $key;
                    $data->user_value = $value;

                    // Update their details in the users table using id as the primary key.
                    $result = JFactory::getDbo()->updateObject('#__ider_user_data', $data, array('uid', 'user_field'));

                }else{
                    // insert data

                    // Create and populate an object.
                    $data = new stdClass();
                    $data->uid = $userID;
                    $data->user_field = $key;
                    $data->user_value = $value;

                    // Insert the object into the user profile table.
                    $result = JFactory::getDbo()->insertObject('#__ider_user_data', $data);

                }


            }catch (Exception $e){

            }

        }

    }

    /**
     * Register an user
     */
    private static function _do_register($userInfo)
    {

        $randomPassword = substr(md5($userInfo->email), 0, 12);

        $data = array(
            'name' => $userInfo->family_name . ' ' . $userInfo->given_name,
            'username' => $userInfo->email,
            'email1' => $userInfo->email,
            'email2' => $userInfo->email,
            'password1' => $randomPassword,
            'password2' => $randomPassword,
            'block' => 0
        );

        JFactory::getLanguage()->load('com_users');
        JModelLegacy::addIncludePath(JPATH_ROOT . '/components/com_users/models');

        $model = JModelLegacy::getInstance('Registration', 'UsersModel');
        $user = $model->register($data);

        $return = null;

        if($user == 'useractivate') {
            $return = self::getUserIdBy('email', $userInfo->email);
        }

        // destroy eventual login sessions
        self::user_logout();

        return $return;

    }

    /**
     * Login an user by its ID
     */
    private static function _login($userID)
    {

        $results = false;

        $loggedUser = JFactory::getUser();

        if($loggedUser->guest) {

            $db = JFactory::getDbo();
            $query = $db->getQuery(true)
                ->select('*')
                ->from($db->quoteName('#__users'))
                ->where($db->quoteName('id') . '=' . $userID);
            $user = $db->setQuery($query, 0, 1)->loadAssoc();

            JPluginHelper::importPlugin('user');
            $dispatcher = JDispatcher::getInstance();

            // Initiate log in
            $options = array('action' => 'core.login.site', 'remember' => false);
            $results = $dispatcher->trigger('onUserLogin', array($user, $options));

        }

        return $results;

    }

    /**
     * Check if the local mail are identical
     */
    private static function _local_mail_identical($userID, $userMail)
    {
        $areIdentical = true;

        // Initialise some variables
        $db = JFactory::getDbo();
        $query = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__ider_user_data'))
            ->where($db->quoteName('uid') . ' = ' . $userID)
            ->where($db->quoteName('user_field') . ' = ' . $db->quote('email'))
            ->where($db->quoteName('user_value') . ' = ' . $db->quote($userMail));

        $db->setQuery($query);
        $db->execute();
        $result = $db->getNumRows();

        if(!$result){
            $areIdentical = false;
        }

        return $areIdentical;

    }

    /**
     * Update the old mail with a new one
     */
    private static function _update_user_mail($userID, $email)
    {

        $db = JFactory::getDbo();
        $query = $db->getQuery(true);
        $fields = array(
            $db->quoteName('email') . ' = ' . $db->quote($email)
        );
        $conditions = array($db->quoteName('id') . ' = ' . $userID);
        $query = $db->getQuery(true);
        $query->update('#__users')->set($fields)->where($conditions);
        $db->setQuery($query);
        $db->execute();

    }

    /**
     *  Fetch user ID by using a custom field
     */
    private static function getUserIdBy($field, $value) {

        // Initialise some variables
        $db = JFactory::getDbo();
        $query = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__users'))
            ->where($db->quoteName($field) . ' = ' . $db->quote($value));
        $db->setQuery($query);

        return $db->loadResult();

    }


    /**
     *  Fetch user ID by using a custom field
     */
    private static function getUserIdBySub($iderSub) {

        // Initialise some variables
        $db = JFactory::getDbo();
        $query = $db->getQuery(true);
        $query
            ->select(array('uid'))
            ->from($db->quoteName('#__ider_user_data'))
            ->where($db->quoteName('user_field') . ' = ' . $db->quote('sub'))
            ->where($db->quoteName('user_value') . ' = ' . $db->quote($iderSub));

        $result = false;
        $db->setQuery($query);
        $db->execute();

        $result = $db->loadResult();

        if($db->getNumRows() > 0) {
            return $result; // If it fails, it will throw a RuntimeException
        }

        return $result;

    }

}