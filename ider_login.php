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
 * @license      https://github.com/iDuckSRL/joomla-ider-login?tab=GPL-3.0-1-ov-file#readme
 */

defined('_JEXEC') or die;

require_once 'vendor/autoload.php';
require_once 'includes/IDER_Server.php';

use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;
use IDERConnect\IDEROpenIDClient;
use Joomla\CMS\Application\CMSApplication;
use Joomla\Event\Event;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Registry\Registry;

/**
 * Plugin class for login/register via IDer service.
 */
class PlgSystemIDer_Login extends CMSPlugin
{
    /**
     * @var Joomla\CMS\Application\CMSApplication The application object.
     */
    protected $app;

    /**
     * @var Joomla\CMS\Uri\Uri The URI object.
     */
    protected $uri;

    protected $pluginSettings;

    /**
     * Constructor.
     */
    public function __construct(&$subject, $config)
    {
        // construct the parent
        parent::__construct($subject, $config);

        $this->app = Factory::getApplication();
        $this->uri = Uri::getInstance();

        IDER_Server::instance();
    }

    /**
     *  Event triggered after the user deleted
     */
    public function onUserAfterDelete($user, $success, $msg){
        $userID = $user['id'];

        if($success){
            IDER_Callback::_delete_ider_data($userID);
        }
    }

    /**
     *  Event triggered after the route dispatching
     */
    public function onAfterInitialise()
    {
        $user = Factory::getUser();

        if (
            $this->app->isClient('site') &&
            $user->guest &&
            preg_match('/\/(?:' . IDEROpenIDClient::$IDERButtonURL . '|' . IDEROpenIDClient::$IDERRedirectURL . ')(?!.)/', $this->uri->getPath())
        ) {
            // Your handling code here
            // Example: Redirect to a specific page or process the callback
            IDER_Server::IDerOpenIdClientHandler();
        }
    }

    /**
     * I add the button for IDer login on the beforeRender event
     */
    public function onBeforeRender()
    {
        /** @var CMSApplication $app */
        $app = Factory::getApplication();

        /** @var HtmlDocument $doc */
        $doc = $app->getDocument();
        
        $isButtonEnabled = $this->params->get('ider_enable_in_login', false);

        if (Factory::getApplication()->isClient('site') && $isButtonEnabled) {

            $doc->addScriptDeclaration("
                document.addEventListener('DOMContentLoaded', () => {
                    const newButton = document.createElement('div');
                    newButton.innerText = 'Login with IDER';
                    newButton.classList.add('ider-login-button');

                    // Find the mod-login__submit button
                    const submitButton = document.querySelector('.mod-login__submit');
                    
                    // Create a new anchor element and set its href attribute
                    const anchor = document.createElement('a');
                    anchor.href = '" . Uri::base() . \IDERConnect\IDEROpenIDClient::$IDERButtonURL . "';
                    anchor.classList.add('ider-login-link');
                    
                    // Append the button to the anchor
                    anchor.appendChild(newButton);

                    // Insert the new button after the submit button
                    submitButton.insertAdjacentElement('afterend', anchor);
                });
            ");

            $doc->addStyleDeclaration("
                .ider-login-link {
                    display: inline-block;
                    text-decoration: none;
                }
                .ider-login-button {
                    text-decoration: none;
                    line-height: 40px;
                    text-shadow: 0 -1px 1px #006799, 1px 0 1px #006799, 0 1px 1px #006799, -1px 0 1px #006799!important;
                    border-color: #0073aa #006799 #006799!important;
                    box-shadow: 0 1px 0 #006799!important;
                    background: none!important;
                    background: url(" . URI::base() . "/plugins/system/ider_login/assets/images/ider_logo_white_256.png)!important;
                    background-size: 50px!important;
                    background-repeat: no-repeat!important;
                    height: 50px!important;
                    width: 195px!important;
                    text-align: right!important;
                    border-radius: 7px!important;
                    background-color: #008ec2!important;
                    color: #fff!important;
                    margin: 3px 0!important;
                    border: none!important;
                    padding: 5px 16px!important;
                    text-transform: uppercase!important;
                }
            ");
        }
    }

    /**
     * Custom IDer events
     */

    // before_callback_handler
    public function onIDerBeforeCallbackHandler(Event $event)
    {
        $scopes = $event->getArgument('openid_connect_scope');

        // Perform your custom logic here
        $handled = false;

        if (in_array('yourscope', $scopes)) {
            // do something...

            // true will prevent further processing
            $handled = true;
        }

        // Set the result in the event
        $event->setArgument('result', $handled);
    }
    
    // after_callback_handler
    public function onIDerAfterCallbackHandler(Event $event)
    {
        $plugin = PluginHelper::getPlugin('system', 'ider_login');
        $pluginParams = new Registry($plugin->params);
        
        $scopes = $event->getArgument('scopes');
        $scopes = $event->getArgument('openid_connect_scope');

        if(!empty($scopes)){
            if (in_array('yourscope', $scopes)) {
                // do something...
            }
        }

        $landingPages = $pluginParams->get('ider_campaigns_landing_pages', '');

        preg_match_all('/^(?!#)([\w-]+)=(.+)/m', $landingPages, $matches);

        $landingPagesArray = array_combine($matches[1], $matches[2]);

        foreach ($landingPagesArray as $scope => $landingPage) {
            if (in_array($scope, $scopes)) {

                $this->app->redirect($landingPage);

                exit;
            }
        }
    }
}
