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

/**
 * Function to transform and map json to local fields.
 */

class IDER_UserInfoManager
{
    static function normalize($user_info)
    {
        $user_info = (array)$user_info;

        // explode json packed claims
        $user_info = self::_checkJsonfields($user_info);

        $user_info = (object)$user_info;

        return $user_info;
    }

    private static function _checkJsonfields($userdata)
    {
        foreach ($userdata as $key => $claim) {
            if (IDER_Helpers::isJSON($claim)) {
                $subclaims = json_decode($claim);

                // break down the claim
                foreach ($subclaims as $subkey => $subclaim) {
                    $userdata[$key . '.' . $subkey] = $subclaim;
                }

                // delete the original claim
                unset($userdata[$key]);
            }
        }

        return $userdata;
    }
}
