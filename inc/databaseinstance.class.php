<?php
/**
 * ---------------------------------------------------------------------
 * GLPI - Gestionnaire Libre de Parc Informatique
 * Copyright (C) 2015-2021 Teclib' and contributors.
 *
 * http://glpi-project.org
 *
 * based on GLPI - Gestionnaire Libre de Parc Informatique
 * Copyright (C) 2003-2014 by the INDEPNET Development Team.
 *
 * ---------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of GLPI.
 *
 * GLPI is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * GLPI is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with GLPI. If not, see <http://www.gnu.org/licenses/>.
 * ---------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

class DatabaseInstance extends CommonDBTM {

   use Glpi\Features\Clonable;
   use Glpi\Features\Inventoriable;

   // From CommonDBTM
   public $dohistory                   = true;
   static $rightname                   = 'database';
   protected $usenotepad               = true;

   public function getCloneRelations() :array {
      return [
         Appliance_Item::class,
         Contract_Item::class,
         Document_Item::class,
         Infocom::class,
         Notepad::class,
         KnowbaseItem_Item::class,
         Certificate_Item::class,
         Domain_Item::class,
         Database::class
      ];
   }

   function useDeletedToLockIfDynamic() {
      return false;
   }

   static function getTypeName($nb = 0) {
      return _n('Database instance', 'Database instances', $nb);
   }

   function defineTabs($options = []) {
      $ong = [];
      $this->addDefaultFormTab($ong)
         ->addImpactTab($ong, $options)
         ->addStandardTab('DatabaseInstance', $ong, $options)
         ->addStandardTab('Database', $ong, $options)
         ->addStandardTab('Infocom', $ong, $options)
         ->addStandardTab('Contract_Item', $ong, $options)
         ->addStandardTab('Document_Item', $ong, $options)
         ->addStandardTab('KnowbaseItem_Item', $ong, $options)
         ->addStandardTab('Ticket', $ong, $options)
         ->addStandardTab('Item_Problem', $ong, $options)
         ->addStandardTab('Change_Item', $ong, $options)
         ->addStandardTab('Certificate_Item', $ong, $options)
         ->addStandardTab('Notepad', $ong, $options)
         ->addStandardTab('Domain_Item', $ong, $options)
         ->addStandardTab('Appliance_Item', $ong, $options)
         ->addStandardTab('Log', $ong, $options);
      return $ong;
   }

   public function getDatabases(): array {
      global $DB;
      $dbs = [];

      $iterator = $DB->request([
         'FROM' => Database::getTable(),
         'WHERE' => [
            'databaseinstances_id' => $this->fields['id'],
            'is_deleted' => 0
         ]
      ]);

      foreach ($iterator as $row) {
         $dbs[$row['id']] = $row;
      }

      return $dbs;
   }

   function showForm($ID, $options = []) {
      global $CFG_GLPI;

      $rand = mt_rand();
      $this->initForm($ID, $options);
      $this->showFormHeader($options);

      echo "<tr class='tab_bg_1'>";

      echo "<td><label for='dropdown_itemtype$rand'>".__('Item type') . "</label></td>";

      $itemtype = $this->fields['itemtype'];
      echo "<td>";
      $rand = Dropdown::showItemTypes(
         'itemtype',
         $CFG_GLPI['databaseinstance_types'], [
            'value' => $itemtype,
            'rand'  => $rand
         ]
      );
      echo "</td>";

      echo "<td><label for='dropdown_items_id$rand'>"._n('Item', 'Items', 1)."</label></td>";

      echo "<td>";
      if ($itemtype) {
         $p = [
            'itemtype' => '__VALUE__',
            'dom_rand' => $rand,
            'dom_name' => "items_id",
            'action' => 'get_items_from_itemtype'
         ];
         Ajax::updateItemOnSelectEvent(
            "dropdown_itemtype$rand",
            "items_id$rand",
            $CFG_GLPI["root_doc"]."/ajax/dropdownAllItems.php",
            $p
         );

         $itemtype::dropdown([
            'name' => 'items_id',
            'value' => $this->fields['items_id'],
            'display_emptychoice' => true,
            'rand' => $rand]);
      } else {
         $p = ['idtable'            => '__VALUE__',
            'rand'                  => $rand,
            'name'                  => "items_id",
            'width'                 => 'unset'
         ];

         Ajax::updateItemOnSelectEvent(
            "dropdown_itemtype$rand",
            "results_itemtype$rand",
            $CFG_GLPI["root_doc"]."/ajax/dropdownAllItems.php", $p);

         echo "<span id='results_itemtype$rand'></span>";
      }
      echo "</td></tr>\n";

      echo "<td><label for='textfield_name$rand'>".__('Name') . "</label></td>";
      echo "<td>";
      echo Html::input(
         'name',
         [
            'value' => $this->fields['name'],
            'id'    => "textfield_name$rand",
         ]
      );
      echo "</td>";
      echo "<td><label for='dropdown_states_id$rand'>".__('Status')."</label></td>";
      echo "<td>";
      State::dropdown([
         'value'     => $this->fields["states_id"],
         'entity'    => $this->fields["entities_id"],
         'condition' => ['is_visible_database' => 1],
         'rand'      => $rand
      ]);
      echo "</td></tr>\n";

      echo "<tr class='tab_bg_1'>";
      echo "<td><label for='dropdown_locations_id$rand'>".Location::getTypeName(1)."</label></td>";
      echo "<td>";
      Location::dropdown(['value'  => $this->fields["locations_id"],
         'entity' => $this->fields["entities_id"],
         'rand' => $rand]);
      echo "</td>";
      echo "<td><label for='dropdown_databaseinstancetypes_id$rand'>".DatabaseInstanceType::getFieldLabel()."</label></td>";
      echo "<td>";
      DatabaseInstanceType::dropdown(['value' => $this->fields["databaseinstancetypes_id"], 'rand' => $rand]);
      echo "</td></tr>\n";

      echo "<tr class='tab_bg_1'>";
      echo "<td><label for='version$rand'>"._n('Version', 'Versions', 1)."</label></td>";
      echo "<td>";
      echo Html::input(
         'version', [
            'id' => 'version'.$rand,
            'value' => $this->fields['version']
         ]
      );
      echo "</td>";
      echo "<td><label for='dropdown_databaseinstancecategories_id$rand'>".DatabaseInstanceCategory::getTypeName(1)."</label></td>";
      echo "<td>";
      DatabaseInstanceCategory::dropdown(['value' => $this->fields["databaseinstancecategories_id"], 'rand' => $rand]);
      echo "</td></tr>\n";

      echo "<tr class='tab_bg_1'>";
      echo "<td><label for='is_active$rand'>".__('Is active')."</label></td>";
      echo "<td>";
      Dropdown::showYesNo('is_active', $this->fields['is_active']);
      echo "<td>" . __('Associable to a ticket') . "</td><td>";
      Dropdown::showYesNo('is_helpdesk_visible', $this->fields['is_helpdesk_visible']);
      echo "</td></tr>\n";

      echo "<tr class='tab_bg_1'>";
      echo "<td><label for='dropdown_groups_id_tech$rand'>".__('Group in charge of the hardware')."</label></td>";
      echo "<td>";
      Group::dropdown([
         'name'      => 'groups_id_tech',
         'value'     => $this->fields['groups_id_tech'],
         'entity'    => $this->fields['entities_id'],
         'condition' => ['is_assign' => 1],
         'rand' => $rand
      ]);

      echo "</td>";

      $rowspan        = 3;

      echo "<td rowspan='$rowspan'><label for='comment'>".__('Comments')."</label></td>";
      echo "<td rowspan='$rowspan' class='middle'>";

      echo "<textarea cols='45' rows='".($rowspan+2)."' id='comment' name='comment' >".
         $this->fields["comment"];
      echo "</textarea></td></tr>";

      echo "<tr class='tab_bg_1'>";
      echo "<td><label for='dropdown_users_id_tech$rand'>".__('Technician in charge of the hardware')."</label></td>";
      echo "<td>";
      User::dropdown(['name'   => 'users_id_tech',
         'value'  => $this->fields["users_id_tech"],
         'right'  => 'own_ticket',
         'entity' => $this->fields["entities_id"],
         'rand'   => $rand]);
      echo "</td></tr>";
      echo "<tr><td><label for='dropdown_manufacturers_id$rand'>".Manufacturer::getTypeName(1)."</label></td>";
      echo "<td>";
      Manufacturer::dropdown(['value' => $this->fields["manufacturers_id"], 'rand' => $rand]);
      echo "</td></tr>\n";

      echo "<tr class='tab_bg_1'>";
      echo "<td><label for='is_onbackup$rand'>".__('Has backup')."</label></td>";
      echo "<td>";
      Dropdown::showYesNo('is_onbackup', $this->fields['is_onbackup']);
      echo "</td>";
      echo "<td><label for='date_lastbackup$rand'>".__('Last backup date')."</label></td>";
      echo "<td>";
      Html::showDateTimeField(
         "date_lastbackup", [
         'value'      => $this->fields['date_lastbackup'],
         'maybeempty' => true
         ]);
      echo "</td></tr>\n";

      $this->showInventoryInfo();

      $this->showFormButtons($options);

      return true;
   }

   public function prepareInputForAdd($input) {
      if (isset($input['date_lastbackup']) && empty($input['date_lastbackup'])) {
         unset($input['date_lastbackup']);
      }

      if (isset($input['size']) && empty($input['size'])) {
         unset($input['size']);
      }

      return $input;
   }

   function rawSearchOptions() {

      $tab = parent::rawSearchOptions();

      $tab[] = [
         'id'                 => '2',
         'table'              => $this->getTable(),
         'field'              => 'id',
         'name'               => __('ID'),
         'massiveaction'      => false, // implicit field is id
         'datatype'           => 'number'
      ];

      $tab = array_merge($tab, Location::rawSearchOptionsToAdd());

      $tab[] = [
         'id'                 => '4',
         'table'              => DatabaseInstanceType::getTable(),
         'field'              => 'name',
         'name'               => _n('Type', 'Types', 1),
         'datatype'           => 'dropdown'
      ];

      $tab[] = [
         'id'                 => '168',
         'table'              => self::getTable(),
         'field'              => 'port',
         'name'               => _n('Port', 'Ports', 1),
         'forcegroupby'       => true,
         'massiveaction'      => false,
         'datatype'           => 'integer',
         'joinparams'         => [
            'jointype'           => 'child'
         ]
      ];

      $tab[] = [
         'id'            => '5',
         'table'         =>  DatabaseInstance::getTable(),
         'field'         => 'items_id',
         'name'               => _n('Associated item', 'Associated items', 2),
         'nosearch'           => true,
         'massiveaction' => false,
         'forcegroupby'  =>  true,
         'additionalfields'   => ['itemtype'],
         'joinparams'    => ['jointype' => 'child']
      ];

      $tab[] = [
         'id'                 => '40',
         'table'              => DatabaseInstanceCategory::getTable(),
         'field'              => 'name',
         'name'               => _n('Category', 'Categories', 1),
         'datatype'           => 'dropdown'
      ];

      $tab[] = [
         'id'                 => '41',
         'table'              => State::getTable(),
         'field'              => 'name',
         'name'               => _n('State', 'States', 1),
         'datatype'           => 'dropdown'
      ];

      $tab[] = [
         'id'                 => '16',
         'table'              => $this->getTable(),
         'field'              => 'comment',
         'name'               => __('Comments'),
         'datatype'           => 'text'
      ];

      $tab[] = [
         'id'                 => '19',
         'table'              => $this->getTable(),
         'field'              => 'date_mod',
         'name'               => __('Last update'),
         'datatype'           => 'datetime',
         'massiveaction'      => false
      ];

      $tab[] = [
         'id'                 => '121',
         'table'              => $this->getTable(),
         'field'              => 'date_creation',
         'name'               => __('Creation date'),
         'datatype'           => 'datetime',
         'massiveaction'      => false
      ];

      $tab[] = [
         'id'                 => '171',
         'table'              => self::getTable(),
         'field'              => 'date_lastboot',
         'name'               => __('Last boot date'),
         'massiveaction'      => false,
         'datatype'           => 'date'
      ];

      $tab[] = [
         'id'                 => '23',
         'table'              => Manufacturer::getTable(),
         'field'              => 'name',
         'name'               => Manufacturer::getTypeName(1),
         'datatype'           => 'dropdown'
      ];

      $tab[] = [
         'id'                 => '24',
         'table'              => User::getTable(),
         'field'              => 'name',
         'linkfield'          => 'users_id_tech',
         'name'               => __('Technician in charge'),
         'datatype'           => 'dropdown',
         'right'              => 'own_ticket'
      ];

      $tab[] = [
         'id'                 => '49',
         'table'              => Group::getTable(),
         'field'              => 'completename',
         'linkfield'          => 'groups_id_tech',
         'name'               => __('Group in charge'),
         'condition'          => ['is_assign' => 1],
         'datatype'           => 'dropdown'
      ];

      $tab = array_merge($tab, Database::rawSearchOptionsToAdd());
      $tab = array_merge($tab, Notepad::rawSearchOptionsToAdd());

      return $tab;
   }

   /**
    * Get item types that can be linked to a database
    *
    * @param boolean $all Get all possible types or only allowed ones
    *
    * @return array
    */
   public static function getTypes($all = false): array {
      global $CFG_GLPI;

      $types = $CFG_GLPI['databaseinstance_types'];

      foreach ($types as $key => $type) {
         if (!class_exists($type)) {
            continue;
         }

         if ($all === false && !$type::canView()) {
            unset($types[$key]);
         }
      }
      return $types;
   }

   function cleanDBonPurge() {
      $this->deleteChildrenAndRelationsFromDb(
         [
            Database::class
         ]
      );
   }

   public function pre_purgeInventory() {
      return true;
   }

   function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) {
      if (!self::canView()) {
         return '';
      }

      $nb = 0;
      if (in_array($item->getType(), self::getTypes(true))) {
         if ($_SESSION['glpishow_count_on_tabs']) {
            $nb = countElementsInTable(self::getTable(), ['itemtype' => $item->getType(), 'items_id' => $item->fields['id']]);
         }
         return self::createTabEntry(self::getTypeName(Session::getPluralNumber()), $nb);
      }
   }

   static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {

      switch ($item->getType()) {
         default :
            if (in_array($item->getType(), self::getTypes())) {
               self::showInstances($item, $withtemplate);
            }
      }
      return true;
   }

   public static function showInstances(CommonDBTM $item, $withtemplate) {
      global $DB;

      $instances = $DB->request([
         'FROM'   => self::getTable(),
         'WHERE'  => [
            'itemtype' => $item->getType(),
            'items_id' => $item->fields['id']
         ]
      ]);

      if (!count($instances)) {
         echo "<table class='tab_cadre_fixe'><tr><th>".__('No instance found')."</th></tr>";
         echo "</table>";
      } else {
         echo "<table class='tab_cadre_fixehov'>";
         $header = "<tr>";
         $header .= "<th>".__('Name')."</th>";
         $header .= "<th></th>";
         $header .= "</tr>";
         echo $header;

         foreach ($instances as $row) {
            $item = new self();
            $item->getFromDB($row['id']);
            echo "<tr lass='tab_bg_1'>";
            echo "<td>" . $item->getLink() . "</td>";
            $databases = $item->getDatabases();
            echo "<td>" . sprintf(_n('%1$d database', '%1$d databases', count($databases)), count($databases)) . "</td>";
            echo "</tr>";
         }
         echo $header;
         echo "</table>";
      }
   }
}
