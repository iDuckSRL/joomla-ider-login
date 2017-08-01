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

/**
 * Function to transform and map json to local fields.
 *
 * @package     WordPress
 * @subpackage  Ider
 * @author      Davide Lattanzio <plugins@jlm.srl>
 * @since       1.0
 *
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