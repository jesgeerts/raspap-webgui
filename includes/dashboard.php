<?php

require_once 'includes/config.php';
require_once 'includes/wifi_functions.php';
require_once 'includes/functions.php';
require_once 'includes/get_clients.php';

/**
 * Show dashboard page.
 */
function DisplayDashboard(&$extraFooterScripts)
{
    getWifiInterface();
    $status = new StatusMessages();
    // Need this check interface name for proper shell execution.
    if (!preg_match('/^([a-zA-Z0-9]+)$/', $_SESSION['wifi_client_interface'])) {
        $status->addMessage(_('Interface name invalid.'), 'danger');
        $status->showMessages();
        return;
    }

    if (!function_exists('exec')) {
        $status->addMessage(_('Required exec function is disabled. Check if exec is not added to php disable_functions.'), 'danger');
        $status->showMessages();
        return;
    }
    
    // ----------------------------- INFOS ABOUT THE ACCESS POINT -------------------------------------------------------------
    
    exec('ip a show '.$_SESSION['ap_interface'], $stdoutIp);
    $stdoutIpAllLinesGlued = implode(" ", $stdoutIp);
    $stdoutIpWRepeatedSpaces = preg_replace('/\s\s+/', ' ', $stdoutIpAllLinesGlued);

    preg_match('/link\/ether ([0-9a-f:]+)/i', $stdoutIpWRepeatedSpaces, $matchesMacAddr) || $matchesMacAddr[1] = _('No MAC Address Found');
    $macAddr = $matchesMacAddr[1];

    $ipv4Addrs = '';
    $ipv4Netmasks = '';
    if (!preg_match_all('/inet (\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})\/([0-3][0-9])/i', $stdoutIpWRepeatedSpaces, $matchesIpv4AddrAndSubnet, PREG_SET_ORDER)) {
        $ipv4Addrs = _('No IPv4 Address Found');
    } else {
        foreach ($matchesIpv4AddrAndSubnet as $inet) {
            $address = $inet[1];
            $suffix  = (int) $inet[2];
            $netmask = long2ip(-1 << (32 - $suffix));

            $ipv4Addrs    .= " $address";
            $ipv4Netmasks .= " $netmask";
        }
        $ipv4Addrs    = trim($ipv4Addrs);
        $ipv4Netmasks = trim($ipv4Netmasks);
    }
    $ipv4Netmasks = empty($ipv4Netmasks) ? "-" : $ipv4Netmasks;

    $ipv6Addrs = '';
    if (!preg_match_all('/inet6 ([a-f0-9:]+)/i', $stdoutIpWRepeatedSpaces, $matchesIpv6Addr)) {
        $ipv6Addrs = _('No IPv6 Address Found');
    } else {
        if (isset($matchesIpv6Addr[1])) {
            $ipv6Addrs = implode(' ', $matchesIpv6Addr[1]);
        }
    }

    preg_match('/state (UP|DOWN)/i', $stdoutIpWRepeatedSpaces, $matchesState) || $matchesState[1] = 'unknown';
    $interfaceState = $matchesState[1];

    // Because of table layout used in the ip output we get the interface statistics directly from
    // the system. One advantage of this is that it could work when interface is disable.
    exec('cat /sys/class/net/'.$_SESSION['ap_interface'].'/statistics/rx_packets ', $stdoutCatRxPackets);
    $strRxPackets = _('No data');
    if (ctype_digit($stdoutCatRxPackets[0])) {
        $strRxPackets = $stdoutCatRxPackets[0];
    }

    exec('cat /sys/class/net/'.$_SESSION['ap_interface'].'/statistics/tx_packets ', $stdoutCatTxPackets);
    $strTxPackets = _('No data');
    if (ctype_digit($stdoutCatTxPackets[0])) {
        $strTxPackets = $stdoutCatTxPackets[0];
    }

    exec('cat /sys/class/net/'.$_SESSION['ap_interface'].'/statistics/rx_bytes ', $stdoutCatRxBytes);
    $strRxBytes = _('No data');
    if (ctype_digit($stdoutCatRxBytes[0])) {
        $strRxBytes = $stdoutCatRxBytes[0];
        $strRxBytes .= getHumanReadableDatasize($strRxBytes);
    }

    exec('cat /sys/class/net/'.$_SESSION['ap_interface'].'/statistics/tx_bytes ', $stdoutCatTxBytes);
    $strTxBytes = _('No data');
    if (ctype_digit($stdoutCatTxBytes[0])) {
        $strTxBytes = $stdoutCatTxBytes[0];
        $strTxBytes .= getHumanReadableDatasize($strTxBytes);
    }

	// ------------------------ INFOS ABOUT THE CLIENT---------------------------------------------------------------
    $clientinfo=array("name"=>"none","type"=>-1,"connected"=>"n");
    $raspi_client=$_SESSION['wifi_client_interface'];
    load_client_config();
    $clients = getClients(false);
    if(!empty($clients)) {
        $ncl=$clients["clients"];
        if($ncl > 0) {
            $ty=-1;
            foreach($clients["device"] as $dev) {
               if(($id=array_search($dev["type"],$_SESSION["net-device-types"])) > $ty && !$dev["isAP"]) {
                 $ty=$id;
                 $clientinfo=$dev;
               }
            }
        }
    }
    if ($clientinfo["name"] != "none") $raspi_client = $clientinfo["name"];
    $interfaceState = $clientinfo["connected"] == "y" ? 'UP' : 'DOWN';
    $txPower="";
    if ($clientinfo["type"] == "wlan") {
        // txpower is now displayed on iw dev(..) info command, not on link command.
        exec('iw dev '.$clientinfo["name"].' info |  sed -rn "s/.*txpower ([0-9]*)[0-9\.]*( dBm).*/\1\2/p"', $stdoutIwInfo);
        if (!empty($stdoutIwInfo)) $txPower=$stdoutIwInfo[0];
    }
	
    $classMsgDevicestatus = 'warning';
    if ($interfaceState === 'UP') {
        $classMsgDevicestatus = 'success';
    }
    if (!RASPI_MONITOR_ENABLED) {
        if (isset($_POST['ifdown_wlan0'])) {
            // Pressed stop button
            if ($interfaceState === 'UP') {
                $status->addMessage(sprintf(_('Interface is going %s.'), _('down')), 'warning');
                exec('sudo /usr/local/sbin/switchClientState.sh down');
                $status->addMessage(sprintf(_('Interface is now %s.'), _('down')), 'success');
            } elseif ($interfaceState === 'unknown') {
                $status->addMessage(_('Interface state unknown.'), 'danger');
            } else {
                $status->addMessage(sprintf(_('Interface already %s.'), _('down')), 'warning');
            }
        } elseif (isset($_POST['ifup_wlan0'])) {
            // Pressed start button
            if ($interfaceState === 'DOWN') {
                $status->addMessage(sprintf(_('Interface is going %s.'), _('up')), 'warning');
                exec('sudo /usr/local/sbin/switchClientState.sh up');
                exec('sudo ip -s a f label ' . $raspi_client);
                $status->addMessage(sprintf(_('Interface is now %s.'), _('up')), 'success');
            } elseif ($interfaceState === 'unknown') {
                $status->addMessage(_('Interface state unknown.'), 'danger');
            } else {
                $status->addMessage(sprintf(_('Interface already %s.'), _('up')), 'warning');
            }
        } else {
            $status->addMessage(sprintf(_('Interface is %s.'), strtolower($interfaceState)), $classMsgDevicestatus);
        }
    }



    // brought in from template
    $arrHostapdConf = parse_ini_file(RASPI_CONFIG.'/hostapd.ini');
    $bridgedEnable = $arrHostapdConf['BridgedEnable'];
    if ($arrHostapdConf['WifiAPEnable'] == 1) {
        $client_interface = 'uap0';
    } else {
        $client_interface = $clientinfo["name"];
    }
    $apInterface = $_SESSION['ap_interface'];
    $MACPattern = '"([[:xdigit:]]{2}:){5}[[:xdigit:]]{2}"';
    if (getBridgedState()) {
        $moreLink = "hostapd_conf";
        exec('iw dev ' . $apInterface . ' station dump | grep -oE ' . $MACPattern, $clients);
    } else {
        $moreLink = "dhcpd_conf";
        exec('cat ' . RASPI_DNSMASQ_LEASES . '| grep -E $(iw dev ' . $apInterface . ' station dump | grep -oE ' . $MACPattern . ' | paste -sd "|")', $clients);
    }
    $ifaceStatus = $clientinfo["connected"]=="y" ? "up" : "down";
    switch($clientinfo["type"]) {
        case "eth":
	        $client_title = "Client: Ethernet cable";
            $type_name = "Ethernet";
            break;
        case "phone":
            $client_title = "Client: Smartphone (USB tethering)";
            $type_name = "Smartphone";
            break;
        case "wlan":
            $client_title = "Wireless Client";
            $type_name = "Wifi";
            break;
        case "ppp":
        case "hilink":
            $client_title = "Mobile Data Client";
            $type_name = "Mobile Data";
            break;
        default: 
            $client_title = "No information for client available";
            $type_name = "No Client";
    }

    echo renderTemplate(
        "dashboard", compact(
            "clients",
			"client_title",
			"type_name",
            "moreLink",
            "apInterface",
            "clientInterface",
            "ifaceStatus",
            "bridgedEnable",
            "status",
            "ipv4Addrs",
            "ipv4Netmasks",
            "ipv6Addrs",
            "macAddr",
            "strRxPackets",
            "strRxBytes",
            "strTxPackets",
            "strTxBytes",
            "txPower",
			"clientinfo"
        )
    );
    $extraFooterScripts[] = array('src'=>'app/js/dashboardchart.js', 'defer'=>false);
    $extraFooterScripts[] = array('src'=>'app/js/linkquality.js', 'defer'=>false);
}

