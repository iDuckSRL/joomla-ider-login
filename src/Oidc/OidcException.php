<?php

/**
 * @package     IDer Login for Joomla
 * @copyright   (C) IDer
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace IDer\Plugin\System\IderLogin\Oidc;

\defined('_JEXEC') or die;

/** Errore tecnico del collegamento con IDer (mai mostrato così com'è all'utente). */
class OidcException extends \RuntimeException
{
}
