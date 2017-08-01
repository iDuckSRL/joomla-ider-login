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

jimport('joomla.plugin');

require_once 'vendor/autoload.php';
require_once 'includes/IDER_Server.php';

/**
 * Plugin class for login/register via IDer service.
 *
 * @since  0.7
 */
class PlgSystemIDer_Login extends JPlugin
{
	/**
	 * Constructor.
	 */
	public function __construct(&$subject, $config)
	{
	    // construct the parent
        parent::__construct($subject, $config);
        IDER_Server::instance();

	}

	public function onAfterRoute(){

	    // hack router
	    $uri = JUri::getInstance();

	    // TODO: check if the user is already logged in

	    if(preg_match('/\/(?:iderbutton|idercallback)(?!.)/', $uri->getPath())) {

	        IDER_Server::IDerOpenIdClientHandler();

        }

	}

	/*
	 * I add the button for IDer login
	 */
	public function onBeforeRender(){

        if(JFactory::getApplication()->isSite()){
            $doc = JFactory::getApplication()->getDocument();
            ob_start();

            $script = ob_get_contents();
            ob_end_clean();
            $doc->addScriptDeclaration($script);

            ob_start();
            ?><a href="/iderbutton"><button type="button" class="btn btn-danger ider-login-button">Login with IDer</button></a><?php
            $html = ob_get_contents();
            ob_end_clean();
            $html = addcslashes($html,"'\"");
            $doc->addScriptDeclaration("
				jQuery(document).ready(function($){
					$('input[name=\"task\"][value=\"user.login\"], form[action*=\"task=user.login\"] > :first-child')
					.closest('form').find('input[type=\"submit\"],button[type=\"submit\"]')
					.after('".$html."');
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
                    margin: 5px 5px 10px 0!important;
                    border: none!important;
                    padding: 7px 24px!important;
                    text-transform: uppercase!important;
                }
            ");
        }


	}

	public function onUserLogout($user, $options = array())
	{
		return true;
	}

	/*
	 * Custom IDer events
	 */

	// before_callback_handler
	public function onIDerBeforeCallbackHandler($userInfo, $scopes){

        $handled = false;
        if (in_array('yourscope', $scopes)) {
            // do something...

            // true will prevent further processing
            $handled = true;
        }
        return $handled;

    }
}
