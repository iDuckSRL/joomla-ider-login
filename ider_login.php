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

jimport('joomla.plugin');

require_once 'vendor/autoload.php';
require_once 'includes/IDER_Server.php';

use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;
use IDERConnect\IDEROpenIDClient;

/**
 * Plugin class for login/register via IDer service.
 */
class PlgSystemIDer_Login extends JPlugin
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

        $isButtonEnabled = $this->params->get('ider_enable_in_login', false);
        if (JFactory::getApplication()->isSite() && $isButtonEnabled) {
            $doc = JFactory::getApplication()->getDocument();
            ob_start();

            $script = ob_get_contents();
            ob_end_clean();
            $doc->addScriptDeclaration($script);

            ob_start();
            ?><a href="<?php echo JUri::base() . \IDERConnect\IDEROpenIDClient::$IDERButtonURL ?>"><button type="button" class="btn btn-danger ider-login-button">Login with IDer</button></a><?php
            $html = ob_get_contents();
            ob_end_clean();
            $html = addcslashes($html, "'\"");
            $doc->addScriptDeclaration("
				jQuery(document).ready(function($){
					$('input[name=\"task\"][value=\"user.login\"], form[action*=\"task=user.login\"] > :first-child')
					.closest('form').find('input[type=\"submit\"],button[type=\"submit\"]')
					.after('" . $html . "');
				});
			");
            $doc->addStyleDeclaration("
                .ider-login-button {
                    text-shadow: 0 -1px 1px #006799, 1px 0 1px #006799, 0 1px 1px #006799, -1px 0 1px #006799!important;
                    border-color: #0073aa #006799 #006799!important;
                    box-shadow: 0 1px 0 #006799!important;
                    background: none!important;
                    background: url(" . JURI::base() . "/plugins/system/ider_login/assets/images/ider_logo_white_256.png)!important;
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
    public function onIDerBeforeCallbackHandler($userInfo, $scopes)
    {

        $handled = false;
        if (in_array('yourscope', $scopes)) {
            // do something...

            // true will prevent further processing
            $handled = true;
        }
        return $handled;

    }

    // after_callback_handler
    public function onIDerAfterCallbackHandler($userInfo, $scopes)
    {

        $app = JFactory::getApplication();
        $pluginParams = new $this->params;

        if(!empty($scopes)){

            if (in_array('yourscope', $scopes)) {
                // do something...
            }

        }

        $landingPages = $pluginParams->get('ider_campaigns_landing_pages');

        preg_match_all('/^(?!#)([\w-]+)=(.+)/m', $landingPages, $matches);

        $landingPagesArray = array_combine($matches[1], $matches[2]);

        foreach ($landingPagesArray as $scope => $landingPage) {
            if (in_array($scope, $scopes)) {

                $app->redirect($landingPage);
                exit;

            }
        }

    }
}
