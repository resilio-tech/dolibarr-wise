<?php
/* Copyright (C) 2026 Resilio SA
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    wise/admin/setup.php
 * \ingroup wise
 * \brief   Wise setup page.
 */

if (!defined('CSRFCHECK_WITH_TOKEN')) {
	define('CSRFCHECK_WITH_TOKEN', '1');
}

$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME']; $tmp2 = realpath(__FILE__); $i = strlen($tmp) - 1; $j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once '../lib/wise.lib.php';
require_once '../class/WiseClient.class.php';

$langs->loadLangs(array("admin", "wise@wise"));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');

if ($action == 'update') {
	$error = 0;
	$privateKey = trim((string) GETPOST('private_key', 'none'));
	if ($privateKey !== '' && @openssl_pkey_get_private($privateKey) === false) {
		setEventMessages($langs->trans("WisePrivateKeyInvalid"), null, 'errors');
		$error++;
	}

	if (!$error) {
		dolibarr_set_const($db, 'WISE_ENVIRONMENT', GETPOST('environment', 'aZ09') === 'live' ? 'live' : 'sandbox', 'chaine', 0, '', $conf->entity);
		dolibarr_set_const($db, 'WISE_PROFILE_ID', (string) GETPOSTINT('profile_id'), 'chaine', 0, '', $conf->entity);
		dolibarr_set_const($db, 'WISE_CURRENCIES', implode(',', wiseCurrencies(GETPOST('currencies', 'alphanohtml'))), 'chaine', 0, '', $conf->entity);
		dolibarr_set_const($db, 'WISE_FETCH_ENABLED', GETPOSTINT('fetch_enabled') ? '1' : '0', 'chaine', 0, '', $conf->entity);
		$token = trim((string) GETPOST('api_token', 'none'));
		if ($token !== '') {
			dolibarr_set_const($db, 'WISE_API_TOKEN', dolEncrypt($token), 'chaine', 0, '', $conf->entity);
		}
		if ($privateKey !== '') {
			dolibarr_set_const($db, 'WISE_PRIVATE_KEY', dolEncrypt($privateKey), 'chaine', 0, '', $conf->entity);
		}
		setEventMessages($langs->trans("RecordSaved"), null, 'mesgs');
	}
}

$testResults = array();
if ($action == 'test') {
	$scaLabels = array(
		WiseClient::SCA_NONE => 'WiseScaNone',
		WiseClient::SCA_APPROVED => 'WiseScaApproved',
		WiseClient::SCA_REJECTED => 'WiseScaRejected',
		WiseClient::SCA_NO_KEY => 'WiseScaNoKey',
	);
	$client = wiseCreateClient();
	if ($client === null) {
		$testResults[] = array($langs->trans("WiseApiToken"), false, $langs->trans("WiseTokenMissing"));
	} else {
		$profiles = $client->listProfiles();
		if ($profiles === null) {
			$testResults[] = array($langs->trans("WiseTestProfiles"), false, $client->error);
		} else {
			$names = array();
			foreach ($profiles as $profile) {
				$name = isset($profile['businessName']) ? $profile['businessName'] : (isset($profile['fullName']) ? $profile['fullName'] : '');
				$names[] = (isset($profile['id']) ? $profile['id'] : '?').' '.(isset($profile['type']) ? $profile['type'] : '').' '.$name;
			}
			$testResults[] = array($langs->trans("WiseTestProfiles"), true, implode(', ', $names));
		}

		$profileId = getDolGlobalInt('WISE_PROFILE_ID');
		if ($profiles !== null && $profileId <= 0) {
			$testResults[] = array($langs->trans("WiseProfileId"), false, $langs->trans("WiseProfileMissing"));
		} elseif ($profiles !== null) {
			$balances = $client->listBalances($profileId);
			if ($balances === null) {
				$testResults[] = array($langs->trans("WiseTestBalances"), false, $client->error);
			} else {
				$list = array();
				foreach ($balances as $balance) {
					$list[] = (isset($balance['currency']) ? $balance['currency'] : '?').' ('.(isset($balance['id']) ? $balance['id'] : '?').')';
				}
				$testResults[] = array($langs->trans("WiseTestBalances"), true, implode(', ', $list));

				$yesterday = wiseYesterday();
				$currencies = wiseCurrencies();
				if (empty($currencies)) {
					$testResults[] = array($langs->trans("WiseCurrencies"), false, $langs->trans("WiseCurrenciesMissing"));
				}
				foreach ($currencies as $currency) {
					$label = $langs->trans("WiseTestStatement", $currency, $yesterday);
					$balanceId = wiseBalanceId($balances, $currency);
					if ($balanceId === null) {
						$testResults[] = array($label, false, $langs->trans("WiseNoBalanceInCurrency"));
						continue;
					}
					$content = $client->fetchStatement($profileId, $balanceId, $currency, $yesterday);
					$sca = $langs->trans("WiseScaStatus").': '.$langs->trans($scaLabels[$client->sca]);
					if ($content === null) {
						$testResults[] = array($label, false, $client->error.' - '.$sca);
					} else {
						$testResults[] = array($label, true, $langs->trans("WiseStatementReceived", strlen($content)).' - '.$sca);
					}
				}
			}
		}
	}
}

$form = new Form($db);

$page_name = "WiseSetup";

llxHeader('', $langs->trans($page_name), '', '', 0, 0, '', '', '', 'mod-wise page-admin');

$linkback = '<a href="'.($backtopage ? $backtopage : DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1').'">'.$langs->trans("BackToModuleList").'</a>';

print load_fiche_titre($langs->trans($page_name), $linkback, 'title_setup');

$head = wiseAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $langs->trans($page_name), -1, 'bank');

print '<span class="opacitymedium">'.$langs->trans("WiseSetupPage").'</span><br><br>';

print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td class="titlefield">'.$langs->trans("Parameter").'</td><td>'.$langs->trans("Value").'</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("WiseEnvironment").'</td><td>';
print $form->selectarray('environment', array('sandbox' => $langs->trans("WiseSandbox"), 'live' => $langs->trans("WiseLive")), getDolGlobalString('WISE_ENVIRONMENT') === 'live' ? 'live' : 'sandbox');
print '</td></tr>';

$keepCurrent = $langs->trans("WiseKeepCurrentSecret");
print '<tr class="oddeven"><td>'.$langs->trans("WiseApiToken").'</td>';
print '<td><input type="password" name="api_token" autocomplete="new-password" class="minwidth300" value="" placeholder="'.dol_escape_htmltag(getDolGlobalString('WISE_API_TOKEN') !== '' ? $keepCurrent : '').'"></td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("WisePrivateKey").'<br><span class="opacitymedium small">'.$langs->trans("WisePrivateKeyHelp").'</span></td>';
print '<td><textarea name="private_key" class="quatrevingtpercent" rows="4" autocomplete="off" placeholder="'.dol_escape_htmltag(getDolGlobalString('WISE_PRIVATE_KEY') !== '' ? $keepCurrent : '-----BEGIN PRIVATE KEY-----').'"></textarea></td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("WiseProfileId").'</td>';
print '<td><input type="text" name="profile_id" class="width100" value="'.(getDolGlobalInt('WISE_PROFILE_ID') > 0 ? getDolGlobalInt('WISE_PROFILE_ID') : '').'"></td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("WiseCurrencies").'</td>';
print '<td><input type="text" name="currencies" class="minwidth200" value="'.dol_escape_htmltag(getDolGlobalString('WISE_CURRENCIES')).'" placeholder="CHF,EUR"></td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("WiseFetchEnabled").'</td>';
print '<td>'.$form->selectyesno('fetch_enabled', getDolGlobalString('WISE_FETCH_ENABLED') === '1' ? 1 : 0, 1).'</td></tr>';

print '</table>';
print '<div class="center" style="margin-top:10px">';
print '<input type="submit" class="button" value="'.$langs->trans("Save").'">';
print '</div>';
print '</form>';

print '<br>';

print load_fiche_titre($langs->trans("WiseTestConnection"), '', '');
print '<span class="opacitymedium">'.$langs->trans("WiseTestConnectionHelp").'</span><br><br>';

if (!empty($testResults)) {
	print '<table class="noborder centpercent">';
	foreach ($testResults as $result) {
		print '<tr class="oddeven"><td class="titlefield">'.dol_escape_htmltag($result[0]).'</td>';
		print '<td>'.($result[1] ? img_picto('', 'tick') : img_error()).' '.dol_escape_htmltag($result[2]).'</td></tr>';
	}
	print '</table><br>';
}

print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="test">';
print '<div class="center">';
print '<input type="submit" class="button" value="'.$langs->trans("WiseTestConnection").'">';
print '</div>';
print '</form>';

print dol_get_fiche_end();

llxFooter();
$db->close();
