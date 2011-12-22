<?php
/*
 * @version $Id$
 -------------------------------------------------------------------------
 GLPI - Gestionnaire Libre de Parc Informatique
 Copyright (C) 2003-2011 by the INDEPNET Development Team.

 http://indepnet.net/   http://glpi-project.org
 -------------------------------------------------------------------------

 LICENSE

 This file is part of GLPI.

 GLPI is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation; either version 2 of the License, or
 (at your option) any later version.

 GLPI is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with GLPI. If not, see <http://www.gnu.org/licenses/>.
 --------------------------------------------------------------------------
 */

// ----------------------------------------------------------------------
// Original Author of file:
// Purpose of file:
// ----------------------------------------------------------------------

function logNetworkPortError($origin, $id, $itemtype, $items_id, $error) {
   global $migration_log_file;

   if ($migration_log_file) {
      fwrite($migration_log_file,
             $origin . " - " . $id . "=" . $itemtype . "[" . $items_id . "] : " . $error . "\n");
   }
}


function logMessage($msg, $andDisplay) {
   global $migration, $migration_log_file;

   if ($migration_log_file) {
      fwrite($migration_log_file, "** $msg\n");
   }

   if ($andDisplay) {
      $migration->displayMessage ($msg);
   }
}


function createNetworkNamesFromItems($itemtype, $itemtable) {
   global $DB, $migration;

   // Retrieve all the networks from the current network ports and add them to the IPNetworks
   $query = "SELECT `ip`, `id`, `entities_id`, `itemtype`, `items_id`
             FROM `$itemtable`
             WHERE `ip` <> ''";

   $networkName = new NetworkName();
   $IPaddress   = new IPAddress();

   foreach ($DB->request($query) as $entry) {
      if (empty($entry["ip"])) {
         continue;
      }

      $IP = $entry["ip"];
      // Using gethostbyaddr() allows us to define its reald internet name according to its IP.
      //   But each gethostbyaddr() may reach several milliseconds. With very large number of
      //   Networkports or NetworkeEquipment, the migration may take several minutes or hours ...
      //$computerName = gethostbyaddr($IP);
      /// TODO moyo : with several private networks gethostbyaddr may get wrong informations
      $computerName = $IP;
      if ($computerName != $IP) {
         $position = strpos($computerName, ".");
         $name     = substr($computerName, 0, $position);
         $domain   = substr($computerName, $position + 1);
         $query    = "SELECT `id`
                      FROM `glpi_fqdns`
                      WHERE `fqdn` = '$domain'";
         $result = $DB->query($query);

         if ($DB->numrows($result) == 1) {
            $data     =$DB->fetch_array($result);
            $domainID = $data['id'];
         }

      } else {
         $name     = "migration-".str_replace('.','-',$computerName);
         $domainID = 0;
      }

      if ($IPaddress->setAddressFromString($IP)) {

         $input = array('name'         => $name,
                        'ip_addresses' => $IPaddress->getTextual(),
                        'fqdns_id'     => $domainID,
                        'entities_id'  => $entry['entities_id'],
                        'items_id'     => $entry['id'],
                        'itemtype'     => $itemtype);

         $networkNameID = $migration->insertInTable($networkName->getTable(), $input);

         $input = $IPaddress->setArrayFromAddress(array('entities_id'   => $entry['entities_id'],
                                                        'itemtype'      => $networkName->getType(),
                                                        'items_id'      => $networkNameID),
                                                  "version", "name", "binary");

         $migration->insertInTable($IPaddress->getTable(), $input);
      } else {
         logNetworkPortError('invalid IP address', $entry["id"], $entry["itemtype"],
                             $entry["items_id"], "$IP");
      }
   }
}


function updateNetworkPortInstantiation($port, $fields, $setNetworkCard) {
   global $DB, $migration;

   $query = "SELECT `name`, `id`, ";

   foreach ($fields as $SQL_field => $field) {
      $query .= "$SQL_field AS $field, ";
   }
   $query .= "    `itemtype`, `items_id`
              FROM `origin_glpi_networkports`
              WHERE `id` IN (SELECT `id`
                             FROM `glpi_networkports`
                             WHERE `instantiation_type` = '".$port->getType()."')";

   foreach ($DB->request($query) as $portInformation) {
      $input = array('id' => $portInformation['id']);
      foreach ($fields as $field) {
         $input[$field] = $portInformation[$field];
      }

      if (($setNetworkCard) && ($portInformation['itemtype'] == 'Computer')) {
         $query = "SELECT link.`id` AS link_id,
                          device.`designation` AS name
                   FROM `glpi_devicenetworkcards` as device,
                        `glpi_computers_devicenetworkcards` as link
                   WHERE link.`computers_id` = ".$portInformation['items_id']."
                         AND device.`id` = link.`devicenetworkcards_id`
                         AND link.`specificity` = '".$portInformation['mac']."'";
         $result = $DB->query($query);

         if ($DB->numrows($result) > 0) {
            $set_first = ($DB->numrows($result) == 1);
            while ($link = $DB->fetch_assoc($result)) {
               if (($set_first) || ($link['name'] == $portInformation['name'])) {
                  $input['computers_devicenetworkcards_id'] = $link['link_id'];
                  break;
               }
            }
         }
      }
      $migration->insertInTable($port->getTable(), $input);
   }
}


/**
 * Update from 0.83 to 0.84
 *
 * @return bool for success (will die for most error)
**/
function update083to084() {
   global $DB, $LANG, $migration;

   $GLOBALS['migration_log_file'] = fopen(GLPI_LOG_DIR."/migration_083_084.log", "w");

   $updateresult     = true;
   $ADDTODISPLAYPREF = array();

   $migration->displayTitle($LANG['install'][4]." -> 0.84");

   // Add the internet field and copy rights from networking
   $migration->addField('glpi_profiles', 'internet', 'char', array('after'  => 'networking',
                                                                   'update' => '`networking`'));

   $backup_tables = false;
   $newtables     = array('glpi_fqdns', 'glpi_ipaddresses', 'glpi_ipnetworks',
                          'glpi_networkaliases', 'glpi_networknames', 'glpi_networkportaggregates',
                          'glpi_networkportdialups', 'glpi_networkportethernets',
                          'glpi_networkportlocals', 'glpi_networkportmigrations',
                          'glpi_networkportwifis', 'glpi_wifinetworks');

   foreach ($newtables as $new_table) {
      // rename new tables if exists ?
      if (TableExists($new_table)) {
         $migration->dropTable("backup_$new_table");
         $migration->displayWarning("$new_table table already exists. ".
                                    "A backup have been done to backup_$new_table.");
         $backup_tables = true;
         $query         = $migration->renameTable("$new_table", "backup_$new_table");
      }
   }
   if ($backup_tables) {
      $migration->displayWarning("You can delete backup tables if you have no need of them.", true);
   }

   $originTables = array();
   foreach (array('glpi_networkports', 'glpi_networkequipments') as $copyTable) {
      $originTable = 'origin_'.$copyTable;
      if (!TableExists($originTable) && TableExists($copyTable)) {
         $migration->copyTable($copyTable, $originTable);
         $originTables[] = $originTable;
         $migration->displayWarning("To be safe, we are working on $originTable. ".
                                    "It is a copy of $copyTable", false);
      }
   }
   if (count($originTables) > 0)
      $migration->displayWarning("You can remove ".implode(', ', $originTables).
                                 " tables if have no need of them.", true);


   logMessage($LANG['install'][4]. " - glpi_fqdns", true);

   // Adding FQDN table
   if (!TableExists('glpi_fqdns')) {
      $query = "CREATE TABLE `glpi_fqdns` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `entities_id` int(11) NOT NULL DEFAULT '0',
                  `is_recursive` tinyint(1) NOT NULL DEFAULT '0',
                  `name` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
                  `fqdn` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
                  `comment` text COLLATE utf8_unicode_ci,
                  PRIMARY KEY (`id`),
                  KEY `name` (`name`),
                  KEY `fqdn` (`fqdn`),
                  KEY `is_recursive` (`is_recursive`)
                ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";
      $DB->query($query) or die("0.84 create glpi_fqdns " . $LANG['update'][90] . $DB->error());

      $fqdn = new FQDN();

      // Then, populate it from domains (beware that "domains" can be FQDNs and Windows workgroups)
      $query = "SELECT DISTINCT LOWER(`name`) AS name, `comment`
                FROM `glpi_domains`";
      foreach ($DB->request($query) as $domain) {
         $domainName = $domain['name'];
         // We ensure that domains have at least 1 dote to be sure it is not a Windows workgroup
         if ((strpos($domainName, '.') !== false) && (FQDN::checkFQDN($domainName))) {
            $migration->insertInTable($fqdn->getTable(),
                                      array('entities_id' => 0,
                                            'name'        => $domainName,
                                            'fqdn'        => $domainName,
                                            'comment'     => $domain['comment']));
         }
      }
      $ADDTODISPLAYPREF['FQDN'] = array(11);
   }

   logMessage($LANG['install'][4]. " - glpi_ipaddresses", true);

   // Adding IPAddress table
   if (!TableExists('glpi_ipaddresses')) {
      $query = "CREATE TABLE `glpi_ipaddresses` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `entities_id` int(11) NOT NULL DEFAULT '0',
                  `items_id` int(11) NOT NULL DEFAULT '0',
                  `itemtype` varchar(100) COLLATE utf8_unicode_ci NOT NULL,
                  `version` tinyint unsigned DEFAULT '0',
                  `name` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
                  `binary_0`  int unsigned NOT NULL DEFAULT '0',
                  `binary_1`  int unsigned NOT NULL DEFAULT '0',
                  `binary_2`  int unsigned NOT NULL DEFAULT '0',
                  `binary_3`  int unsigned NOT NULL DEFAULT '0',
                  PRIMARY KEY (`id`),
                  KEY `textual` (`name`),
                  KEY `binary` (`binary_0`, `binary_1`, `binary_2`, `binary_3`),
                  KEY `item` (`items_id`,`itemtype`)
                ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";
      $DB->query($query)
      or die("0.84 create glpi_ipaddresses " . $LANG['update'][90] .$DB->error());
   }

   logMessage($LANG['install'][4]. " - glpi_wifinetworks", true);

   // Adding WifiNetwork table
   if (!TableExists('glpi_wifinetworks')) {
      $query = "CREATE TABLE `glpi_wifinetworks` (
                 `id` int(11) NOT NULL AUTO_INCREMENT,
                 `name` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
                 `essid` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
                 `mode` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL
                        COMMENT 'ad-hoc, access_point',
                 `comment` text COLLATE utf8_unicode_ci,
                 PRIMARY KEY (`id`),
                 KEY `essid` (`essid`),
                 KEY `name` (`name`)
               ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";
      $DB->query($query)
      or die("0.84 create glpi_wifinetworks " . $LANG['update'][90] . $DB->error());

      $ADDTODISPLAYPREF['WifiNetwork'] = array(10);
   }

   logMessage($LANG['install'][4]. " - glpi_ipnetworks", true);

   // Adding IPNetwork table
   if (!TableExists('glpi_ipnetworks')) {
      $query = "CREATE TABLE `glpi_ipnetworks` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `entities_id` int(11) NOT NULL DEFAULT '0',
                  `is_recursive` tinyint(1) NOT NULL DEFAULT '0',
                  `version` tinyint unsigned DEFAULT '0',
                  `name` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
                  `address` varchar(40) COLLATE utf8_unicode_ci DEFAULT NULL,
                  `address_0`  int unsigned NOT NULL DEFAULT '0',
                  `address_1`  int unsigned NOT NULL DEFAULT '0',
                  `address_2`  int unsigned NOT NULL DEFAULT '0',
                  `address_3`  int unsigned NOT NULL DEFAULT '0',
                  `netmask` varchar(40) COLLATE utf8_unicode_ci DEFAULT NULL,
                  `netmask_0`  int unsigned NOT NULL DEFAULT '0',
                  `netmask_1`  int unsigned NOT NULL DEFAULT '0',
                  `netmask_2`  int unsigned NOT NULL DEFAULT '0',
                  `netmask_3`  int unsigned NOT NULL DEFAULT '0',
                  `gateway` varchar(40) COLLATE utf8_unicode_ci DEFAULT NULL,
                  `gateway_0`  int unsigned NOT NULL DEFAULT '0',
                  `gateway_1`  int unsigned NOT NULL DEFAULT '0',
                  `gateway_2`  int unsigned NOT NULL DEFAULT '0',
                  `gateway_3`  int unsigned NOT NULL DEFAULT '0',
                  `comment` text COLLATE utf8_unicode_ci,
                  PRIMARY KEY (`id`),
                  KEY `network_definition` (`entities_id`,`address`,`netmask`),
                  KEY `address` (`address_0`, `address_1`, `address_2`, `address_3`),
                  KEY `netmask` (`netmask_0`, `netmask_1`, `netmask_2`, `netmask_3`),
                  KEY `gateway` (`gateway_0`, `gateway_1`, `gateway_2`, `gateway_3`),
                  KEY `name` (`name`)
                ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";
      $DB->query($query)
      or die("0.84 create glpi_ipnetworks " . $LANG['update'][90] . $DB->error());

      // Retrieve all the networks from the current network ports and add them to the IPNetworks
      $query = "SELECT DISTINCTROW INET_NTOA(INET_ATON(`ip`)&INET_ATON(`netmask`)) AS address,
                     `netmask`, `gateway`, `entities_id`
                FROM `origin_glpi_networkports`
                ORDER BY `gateway` DESC";
      $address = new IPAddress();
      $netmask = new IPNetmask();
      $gateway = new IPAddress();
      $network = new IPNetwork();
      foreach ($DB->request($query) as $entry) {

         $address = $entry['address'];
         $netmask = $entry['netmask'];
         $gateway = $entry['gateway'];

         if ((empty($address)) || ($address == '0.0.0.0') || (empty($netmask))
             || ($netmask == '0.0.0.0') || ($netmask == '255.255.255.255')) {
            continue;
         }

         if ($gateway == '0.0.0.0') {
            $gateway = '';
         }

         $networkName   = $address."/".$netmask.
                          (empty($entry['gateway']) ? "" : " - ".$entry['gateway']);

         $input         = array('entities_id' => $entry['entities_id'],
                                'name'        => $networkName,
                                'network'     => $address."/".$netmask,
                                'gateway'     => $entry["gateway"]);

         $preparedInput = $network->prepareInput($input);

         if (is_array($preparedInput['input'])) {
            $input = $preparedInput['input'];
            if (isset($preparedInput['error'])) {
               $query = "SELECT id, items_id, itemtype
                         FROM origin_glpi_networkports
                         WHERE INET_NTOA(INET_ATON(`ip`)&INET_ATON(`netmask`))='".$entry['address']."'
                               AND `netmask` = '".$entry['netmask']."'
                               AND `gateway` = '".$entry['gateway']."'
                               AND `entities_id` = '".$entry['entities_id']."'";
               $result = $DB->query($query);
               foreach ($DB->request($query) as $data) {
                  logNetworkPortError('network warning', $data['id'], $data['itemtype'],
                                      $data['items_id'], $preparedInput['error']);
               }
            }
            $migration->insertInTable($network->getTable(), $input);
         } else if (isset($preparedInput['error'])) {
            $query = "SELECT id, items_id, itemtype
                      FROM origin_glpi_networkports
                      WHERE INET_NTOA(INET_ATON(`ip`)&INET_ATON(`netmask`))='".$entry['address']."'
                            AND `netmask` = '".$entry['netmask']."'
                            AND `gateway` = '".$entry['gateway']."'
                            AND `entities_id` = '".$entry['entities_id']."'";
            $result = $DB->query($query);
            foreach ($DB->request($query) as $data) {
               logNetworkPortError('network error', $data['id'], $data['itemtype'],
                                   $data['items_id'], $preparedInput['error']);
            }
         }
      }
      $ADDTODISPLAYPREF['IPNetwork'] = array(10, 11, 12, 13);
   }

   logMessage($LANG['install'][4]. " - glpi_networknames", true);

   // Adding NetworkName table
   if (!TableExists('glpi_networknames')) {
      $query = "CREATE TABLE `glpi_networknames` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `entities_id` int(11) NOT NULL DEFAULT '0',
                  `items_id` int(11) NOT NULL DEFAULT '0',
                  `itemtype` varchar(100) COLLATE utf8_unicode_ci NOT NULL,
                  `name` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
                  `comment` text COLLATE utf8_unicode_ci,
                  `fqdns_id` int(11) NOT NULL DEFAULT '0',
                  `ip_addresses` TEXT COLLATE utf8_unicode_ci COMMENT 'caching value of IPAddress',
                  PRIMARY KEY (`id`),
                  KEY `FQDN` (`name`,`fqdns_id`),
                  KEY `name` (`name`),
                  KEY `item` (`items_id`, `itemtype`),
                  KEY `fqdns_id` (`fqdns_id`)
                ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";
      $DB->query($query)
      or die("0.84 create glpi_networknames " . $LANG['update'][90] . $DB->error());

      $ADDTODISPLAYPREF['NetworkName'] = array(12, 13);

      createNetworkNamesFromItems("NetworkPort", "origin_glpi_networkports");
      createNetworkNamesFromItems("NetworkEquipment", "origin_glpi_networkequipments");

   }

   logMessage($LANG['install'][4]. " - glpi_networkaliases", true);

   // Adding NetworkAlias table
   if (!TableExists('glpi_networkaliases')) {
      $query = "CREATE TABLE `glpi_networkaliases` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `entities_id` int(11) NOT NULL DEFAULT '0',
                  `networknames_id` int(11) NOT NULL DEFAULT '0',
                  `name` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
                  `fqdns_id` int(11) NOT NULL DEFAULT '0',
                  `comment` text COLLATE utf8_unicode_ci,
                  PRIMARY KEY (`id`),
                  KEY `name` (`name`),
                  KEY `networknames_id` (`networknames_id`)
                ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";
      $DB->query($query)
      or die("0.84 create glpi_networkaliases " . $LANG['update'][90] . $DB->error());
   }

   logMessage($LANG['install'][4]. " - glpi_networkinterfaces", true);

   // Update NetworkPorts
   $migration->addField('glpi_networkports', 'instantiation_type', 'string',
                        array('after'  => 'name',
                              'update' => "'NetworkPortEthernet'"));

   logMessage($LANG['install'][4]. " - glpi_networkports", true);

   // Retrieve all the networks from the current network ports and add them to the IPNetworks
   $query = "SELECT *
             FROM `glpi_networkinterfaces`";

   foreach ($DB->request($query) as $entry) {
      switch ($entry['name']) {
         case 'Local' :
            $instantiation_type = "NetworkPortLocal";
            break;

         case 'Ethernet' :
            $instantiation_type = "NetworkPortEthernet";
            break;

         case 'Wifi' :
            $instantiation_type = "NetworkPortWifi";
            break;

         case 'Dialup' :
            $instantiation_type = "NetworkPortDialup";
            break;


         default:
            $instantiation_type = "NetworkPortMigration";
            break;

       }
      if (isset($instantiation_type)) {
         $query = "UPDATE `glpi_networkports`
                   SET `instantiation_type` = '$instantiation_type'
                   WHERE `id` IN (SELECT `id`
                                  FROM `origin_glpi_networkports`
                                  WHERE `networkinterfaces_id` = '".$entry['id']."')";
         $DB->query($query)
         or die("0.84 update instantiation_type field of glpi_networkports " .
                $LANG['update'][90] . $DB->error());
         // Clear $instantiation_type for next check inside the loop
         unset($instantiation_type);
      }
   }
   $migration->displayWarning("You can delete glpi_networkinterfaces table if you have no need
                              of them.", true);

   foreach (array('ip', 'gateway', 'mac', 'netmask', 'netpoints_id', 'networkinterfaces_id',
                  'subnet') as $field) {
      $migration->dropField('glpi_networkports', $field);
   }

   logMessage($LANG['install'][4]. " - glpi_networkportethernets", true);

   // Adding NetworkPortEthernet table
   if (!TableExists('glpi_networkportethernets')) {
      $query = "CREATE TABLE `glpi_networkportethernets` (
                  `id` int(11) NOT NULL,
                  `computers_devicenetworkcards_id` int(11) NOT NULL DEFAULT '0',
                  `netpoints_id` int(11) NOT NULL DEFAULT '0',
                  `mac` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
                  `type` varchar(10) COLLATE utf8_unicode_ci DEFAULT NULL COMMENT 'T, LX, SX',
                  `speed` int(11) NOT NULL DEFAULT '10' COMMENT '10, 100, 1000, 10000',
                  PRIMARY KEY (`id`),
                  KEY `card` (`computers_devicenetworkcards_id`),
                  KEY `netpoint` (`netpoints_id`),
                  KEY `mac` (`mac`),
                  KEY `type` (`type`),
                  KEY `speed` (`speed`)
                ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";
      $DB->query($query)
      or die("0.84 create glpi_networkportethernets " . $LANG['update'][90] . $DB->error());

      $port = new NetworkPortEthernet();
      updateNetworkPortInstantiation($port, array("LOWER(`mac`)"   => 'mac',
                                                  '`netpoints_id`' => 'netpoints_id'),
                                     true);
   }

   logMessage($LANG['install'][4]. " - glpi_networkportwifis", true);

  // Adding NetworkPortWifi table
   if (!TableExists('glpi_networkportwifis')) {
      $query = "CREATE TABLE `glpi_networkportwifis` (
                  `id` int(11) NOT NULL,
                  `computers_devicenetworkcards_id` int(11) NOT NULL DEFAULT '0',
                  `mac` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
                  `wifinetworks_id` int(11) NOT NULL DEFAULT '0',
                  `networkportwifis_id` int(11) NOT NULL DEFAULT '0'
                                        COMMENT 'only usefull in case of Managed node',
                  `version` varchar(20) COLLATE utf8_unicode_ci DEFAULT NULL
                            COMMENT 'a, a/b, a/b/g, a/b/g/n, a/b/g/n/y',
                  `mode` varchar(20) COLLATE utf8_unicode_ci DEFAULT NULL
                         COMMENT 'ad-hoc, managed, master, repeater, secondary, monitor, auto',
                  PRIMARY KEY (`id`),
                  KEY `mac` (`mac`),
                  KEY `card` (`computers_devicenetworkcards_id`),
                  KEY `essid` (`wifinetworks_id`),
                  KEY `version` (`version`),
                  KEY `mode` (`mode`)
                ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";
      $DB->query($query)
      or die("0.84 create glpi_networkportwifis " . $LANG['update'][90] . $DB->error());

      $port = new NetworkPortWifi();
      updateNetworkPortInstantiation($port, array("LOWER(`mac`)" => 'mac'), true);
   }

   logMessage($LANG['install'][4]. " - glpi_networkportlocals", true);

   // Adding NetworkPortLocal table
   if (!TableExists('glpi_networkportlocals')) {
      $query = "CREATE TABLE `glpi_networkportlocals` (
                  `id` int(11) NOT NULL,
                  PRIMARY KEY (`id`)
                ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";
      $DB->query($query)
      or die("0.84 create glpi_networkportlocals " . $LANG['update'][90] . $DB->error());

      $port = new NetworkPortLocal();
      updateNetworkPortInstantiation($port, array(), false);
   }

   logMessage($LANG['install'][4]. " - glpi_networkportdilups", true);

   // Adding NetworkPortDialup table
   if (!TableExists('glpi_networkportdialups')) {
      $query = "CREATE TABLE `glpi_networkportdialups` (
                  `id` int(11) NOT NULL,
                  `mac` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
                  PRIMARY KEY (`id`),
                  KEY `mac` (`mac`)
                ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";
      $DB->query($query)
      or die("0.84 create glpi_networkportdialups " . $LANG['update'][90] . $DB->error());

      $port = new NetworkPortDialup();
      updateNetworkPortInstantiation($port, array("LOWER(`mac`)" => 'mac'), true);
   }

   logMessage($LANG['install'][4]. " - glpi_networkportmigrations", true);

   // Adding NetworkPortMigration table
   if (!TableExists('glpi_networkportmigrations')) {
      $query = "CREATE TABLE `glpi_networkportmigrations` (
                  `id` int(11) NOT NULL,
                  `mac` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
                  `networkinterfaces_id` int(11) NOT NULL DEFAULT '0',
                  `netpoints_id` int(11) NOT NULL DEFAULT '0',
                  PRIMARY KEY (`id`),
                  KEY `mac` (`mac`),
                  KEY `networkinterfaces_id` (`networkinterfaces_id`),
                  KEY `netpoints_id` (`netpoints_id`)
                ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";
      $DB->query($query)
      or die("0.84 create glpi_networkportmigrations " . $LANG['update'][90] . $DB->error());

      $port = new NetworkPortMigration();
      updateNetworkPortInstantiation($port, array("LOWER(`mac`)"           => 'mac',
                                                  '`netpoints_id`'         => 'netpoints_id',
                                                  '`networkinterfaces_id`' =>
                                                  'networkinterfaces_id'),
                                     true);
   }

   logMessage($LANG['install'][4]. " - glpi_networkportaggregates", true);

   // Adding NetworkPortAggregate table
   if (!TableExists('glpi_networkportaggregates')) {
      $query = "CREATE TABLE `glpi_networkportaggregates` (
                  `id` int(11) NOT NULL,
                  `networkports_id` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL
                                    COMMENT 'array of associated networkports_id',
                  `mac` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
                  PRIMARY KEY (`id`),
                  KEY `mac` (`mac`)
                ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";
      $DB->query($query)
      or die("0.84 create glpi_networkportaggregates " . $LANG['update'][90] . $DB->error());

      // New element, so, we don't need to create items
   }

   logMessage($LANG['install'][4]. " - glpi_networkportaliases", true);

   // Adding NetworkPortAlias table
   if (!TableExists('glpi_networkportaliases')) {
      $query = "CREATE TABLE `glpi_networkportaliases` (
                  `id` int(11) NOT NULL,
                  `networkports_id` int(11) NOT NULL,
                  `mac` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
                  PRIMARY KEY (`id`),
                  KEY `networkports_id` (`networkports_id`),
                  KEY `mac` (`mac`)
                ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";
      $DB->query($query)
      or die("0.84 create glpi_networkportaliases " . $LANG['update'][90] . $DB->error());

      // New element, so, we don't need to create items
   }

   $migration->addField('glpi_networkports_vlans', 'tagged', 'char', array('value' => '0'));

   $migration->addField('glpi_mailcollectors', 'accepted', 'string');
   $migration->addField('glpi_mailcollectors', 'refused', 'string');

   // Clean display prefs
   $query = "UPDATE `glpi_displaypreferences` SET `num` = 160 WHERE `itemtype` = 'Software' AND `num` = 7";
   $DB->query($query);   

   // ************ Keep it at the end **************
   //TRANS: %s is the table or item to migrate
   $migration->displayMessage(sprintf(__('Data migration - %s')),'glpi_displaypreferences');

   foreach ($ADDTODISPLAYPREF as $type => $tab) {
      $query = "SELECT DISTINCT `users_id`
                FROM `glpi_displaypreferences`
                WHERE `itemtype` = '$type'";

      if ($result = $DB->query($query)) {
         if ($DB->numrows($result)>0) {
            while ($data = $DB->fetch_assoc($result)) {
               $query = "SELECT MAX(`rank`)
                         FROM `glpi_displaypreferences`
                         WHERE `users_id` = '".$data['users_id']."'
                               AND `itemtype` = '$type'";
               $result = $DB->query($query);
               $rank   = $DB->result($result,0,0);
               $rank++;

               foreach ($tab as $newval) {
                  $query = "SELECT *
                            FROM `glpi_displaypreferences`
                            WHERE `users_id` = '".$data['users_id']."'
                                  AND `num` = '$newval'
                                  AND `itemtype` = '$type'";
                  if ($result2=$DB->query($query)) {
                     if ($DB->numrows($result2)==0) {
                        $query = "INSERT INTO `glpi_displaypreferences`
                                         (`itemtype` ,`num` ,`rank` ,`users_id`)
                                  VALUES ('$type', '$newval', '".$rank++."',
                                          '".$data['users_id']."')";
                        $DB->query($query);
                     }
                  }
               }
            }

         } else { // Add for default user
            $rank = 1;
            foreach ($tab as $newval) {
               $query = "INSERT INTO `glpi_displaypreferences`
                                (`itemtype` ,`num` ,`rank` ,`users_id`)
                         VALUES ('$type', '$newval', '".$rank++."', '0')";
               $DB->query($query);
            }
         }
      }
   }


   if ($GLOBALS['migration_log_file']) {
      fclose($GLOBALS['migration_log_file']);
   }

   // must always be at the end
   $migration->executeMigration();

   return $updateresult;
}
?>
