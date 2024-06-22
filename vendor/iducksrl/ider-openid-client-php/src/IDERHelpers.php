<?php

namespace IDERConnect;

/**
 * IDER Helpers Class
 */
class IDERHelpers
{

    static function logRotate($text, $fullpath)
    {
        $text = "[" . date("Y-m-d H:i:s") . "] " . $text . "\n";

        $fileparts = pathinfo($fullpath);

        $ext = '.' . $fileparts['extension'];
        $filename = $fileparts['filename'];
        $dirname = $fileparts['dirname'];


        if (!is_dir($fileparts['dirname'])) {
            mkdir($dirname, true);
        }

        if (!file_exists($fullpath)) {
            touch($fullpath);
            chmod($fullpath, 0666);
        }

        // 5 mb
        if (@filesize($fullpath) > 5 * 1024 * 1024) {

            // search for available filename
            $n = 1;
            while (file_exists($filename . '.' . $n . $ext)) {
                $n++;
            }

            rename($fullpath, $filename . '.' . $n . $ext);

            touch($fullpath);
            chmod($fullpath, 0666);
        }

        if (!is_writable($fullpath)) {
            error_log("Cannot open log file ($filename$ext)");
        }

        if (!$handle = fopen($fullpath, 'a')) {
            echo "Cannot open file (" . $fullpath . ")";
        }

        if (fwrite($handle, $text) === FALSE) {
            echo "Cannot write to file (" . $fullpath . ")";
        }

        fclose($handle);
    }

    /**
     * A wrapper around base64_decode which decodes Base64URL-encoded data,
     * which is not the same alphabet as base64.
     */
    static function base64url_decode($base64url)
    {
        return base64_decode(static::b64url2b64($base64url));
    }

    /**
     * Per RFC4648, "base64 encoding with URL-safe and filename-safe
     * alphabet".  This just replaces characters 62 and 63.  None of the
     * reference implementations seem to restore the padding if necessary,
     * but we'll do it anyway.
     */
    static function b64url2b64($base64url)
    {
        // "Shouldn't" be necessary, but why not
        $padding = strlen($base64url) % 4;

        if ($padding > 0) {
            $base64url .= str_repeat("=", 4 - $padding);
        }
        
        return strtr($base64url, '-_', '+/');
    }

    /**
     * Remap fields for user information.
     *
     * This function is called by every integration. It checks if the user information contains an address,
     * and if the address is in the US, it maps the postal code to the corresponding state.
     *
     * @param array $userinfo The user information array containing address details.
     * @return array The updated user information with the state mapped from the postal code if the address is in the US.
     */
    static function remapFields($userinfo)
    {
        // this function will be called by every integration
        if (
            key_exists('address', $userinfo)
        ) {

            // US remap
            if (
                $userinfo['address'] &&
                key_exists('country', $userinfo['address']) && $userinfo['address']['country'] === 'US' &&
                key_exists('postal_code', $userinfo['address']) && $userinfo['address']['postal_code']
            ) {
                $user_zip_code = intval($userinfo['address']['postal_code']);

                $us_zip_ranges = [
                    'AL' => [[35000, 36999]],
                    'AK' => [[99500, 99999]],
                    'AZ' => [[85000, 86999]],
                    'AR' => [[71600, 72999], [75502, 75502]],
                    'CA' => [[90000, 96199]],
                    'CO' => [[80000, 81699]],
                    'CT' => [[6000, 6999]],
                    'DE' => [[19700, 19999]],
                    'FL' => [[32000, 34999]],
                    'GA' => [[30000, 31999], [39800, 39999]],
                    'HI' => [[96700, 96999]],
                    'ID' => [[83200, 83999]],
                    'IL' => [[60000, 62999]],
                    'IN' => [[46000, 47999]],
                    'IA' => [[50000, 52999]],
                    'KS' => [[66000, 67999]],
                    'KY' => [[40000, 42999]],
                    'LA' => [[70000, 71599]],
                    'ME' => [[3900, 4999]],
                    'MD' => [[20600, 21999]],
                    'MA' => [[1000, 2799], [5500, 5599]],
                    'MI' => [[48000, 49999]],
                    'MN' => [[55000, 56999]],
                    'MS' => [[38600, 39999]],
                    'MO' => [[63000, 65999]],
                    'MT' => [[59000, 59999]],
                    'NE' => [[68000, 69999]],
                    'NV' => [[88900, 89999]],
                    'NH' => [[3000, 3899]],
                    'NJ' => [[7000, 8999]],
                    'NM' => [[87000, 88499]],
                    'NY' => [[10000, 14999]],
                    'NC' => [[27000, 28999]],
                    'ND' => [[58000, 58999]],
                    'OH' => [[43000, 45999]],
                    'OK' => [[73000, 74999]],
                    'OR' => [[97000, 97999]],
                    'PA' => [[15000, 19699]],
                    'RI' => [[2800, 2999]],
                    'SC' => [[29000, 29999]],
                    'SD' => [[57000, 57999]],
                    'TN' => [[37000, 38599]],
                    'TX' => [[75000, 79999], [88500, 88599]],
                    'UT' => [[84000, 84999]],
                    'VT' => [[5000, 5999]],
                    'VA' => [[20100, 20199], [22000, 24699]],
                    'WA' => [[98000, 99499]],
                    'WV' => [[24700, 26899]],
                    'WI' => [[53000, 54999]],
                    'WY' => [[82000, 83199]],
                    'DC' => [[20000, 20099]],
                    'AS' => [[96799, 96799]],
                    'GU' => [[96910, 96932]],
                    'MP' => [[96950, 96952]],
                    'PR' => [[600, 799], [900, 999]],
                    'VI' => [[800, 899]],
                ];

                foreach ($us_zip_ranges as $us_state => $us_ranges) {
                    foreach ($us_ranges as $us_range) {
                        if ($user_zip_code >= $us_range[0] && $user_zip_code <= $us_range[1]) {
                            // improper, but it will do it
                            $userinfo['address']['state'] = $us_state;
                        }
                    }
                }
            } else {
                if (key_exists('region', $userinfo['address'])) {
                    $userinfo['address']['state'] = $userinfo['address']['region'];
                }
            }

            return $userinfo;
        }
    }

    /**
     * Convert object to array.
     */
    static function toArray($data) 
    {
        if (is_object($data)) {
            $data = get_object_vars($data);
        }
    
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                if (is_string($value) && self::isJSON($value)) {
                    $data[$key] = json_decode($value, true);
                } else {
                    $data[$key] = self::toArray($value);
                }
            }
        }
    
        return $data;
    }

    /**
     * Check if a string is a JSON.
     */
    static function isJSON($string){
        return is_string($string) && is_array(json_decode($string, true)) && (json_last_error() == JSON_ERROR_NONE) ? true : false;
    }
}
