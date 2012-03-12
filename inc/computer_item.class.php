<?php
/*
 * @version $Id$
 -------------------------------------------------------------------------
 GLPI - Gestionnaire Libre de Parc Informatique
 Copyright (C) 2003-2012 by the INDEPNET Development Team.

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
// Original Author of file: Julien Dombre
// Purpose of file:
// ----------------------------------------------------------------------

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

// Relation between Computer and Items (monitor, printer, phone, peripheral only)
class Computer_Item extends CommonDBRelation{

   // From CommonDBRelation
   public $itemtype_1 = 'Computer';
   public $items_id_1 = 'computers_id';

   public $itemtype_2 = 'itemtype';
   public $items_id_2 = 'items_id';


   /**
    * Count connection for an item
    *
    * @param $item   CommonDBTM object
    *
    * @return integer: count
   **/
   static function countForItem(CommonDBTM $item) {

      return countElementsInTable('glpi_computers_items',
                                  "`itemtype` = '".$item->getType()."'
                                   AND `items_id` ='".$item->getField('id')."'");
   }


   /**
    * Count connection for a Computer
    *
    * @param $comp   Computer object
    *
    * @return integer: count
   **/
   static function countForComputer(Computer $comp) {

      return countElementsInTable('glpi_computers_items',
                                  "`computers_id` ='".$comp->getField('id')."'");
   }


   /**
    * Check right on an item - overloaded to check is_global
    *
    * @param $ID           ID of the item (-1 if new item)
    * @param $right        Right to check : r / w / recursive
    * @param $input  array of input data (used for adding item) (default NULL)
    *
    * @return boolean
   **/
   function can($ID, $right, array &$input=NULL) {

      if ($ID < 0) {
         // Ajout
         if (!($item = getItemForItemtype($input['itemtype']))) {
            return false;
         }

         if (!$item->getFromDB($input['items_id'])) {
            return false;
         }

         if (($item->getField('is_global') == 0)
             && ($this->countForItem($item) > 0)) {
               return false;
         }
      }
      return parent::can($ID, $right, $input);
   }


   /**
    * Prepare input datas for adding the relation
    *
    * Overloaded to check is Disconnect needed (during OCS sync)
    * and to manage autoupdate feature
    *
    * @param $input array of datas used to add the item
    *
    * @return the modified $input array
    *
   **/
   function prepareInputForAdd($input) {
      global $DB, $CFG_GLPI;

      switch ($input['itemtype']) {
         case 'Monitor' :
            $item   = new Monitor();
            $ocstab = 'import_monitor';
            break;

         case 'Phone' :
            // shoul really never occurs as OCS doesn't sync phone
            $item   = new Phone();
            $ocstab = '';
            break;

         case 'Printer' :
            $item   = new Printer();
            $ocstab = 'import_printer';
            break;

         case 'Peripheral' :
            $item   = new Peripheral();
            $ocstab = 'import_peripheral';
            break;

         default :
            return false;
      }

      if (!$item->getFromDB($input['items_id'])) {
         return false;
      }
      if (!$item->getField('is_global') ) {
         // Handle case where already used, should never happen (except from OCS sync)
         $query = "SELECT `id`, `computers_id`
                   FROM `glpi_computers_items`
                   WHERE `glpi_computers_items`.`items_id` = '".$input['items_id']."'
                         AND `glpi_computers_items`.`itemtype` = '".$input['itemtype']."'";
         $result = $DB->query($query);

         while ($data = $DB->fetch_assoc($result)) {
            $temp = clone $this;
            $temp->delete($data);
            if ($ocstab) {
               OcsServer::deleteInOcsArray($data["computers_id"], $data["id"],$ocstab);
            }
         }

         // Autoupdate some fields - should be in post_addItem (here to avoid more DB access)
         $comp = new Computer();
         if ($comp->getFromDB($input['computers_id'])) {
            $updates = array();

            if ($CFG_GLPI["is_location_autoupdate"]
                && ($comp->fields['locations_id'] != $item->getField('locations_id'))) {

               $updates['locations_id'] = addslashes($comp->fields['locations_id']);
               Session::addMessageAfterRedirect(
                  __('Location updated. The connected items have been moved in the same location.'),
                  true);
            }
            if (($CFG_GLPI["is_user_autoupdate"]
                 && ($comp->fields['users_id'] != $item->getField('users_id')))
                || ($CFG_GLPI["is_group_autoupdate"]
                    && ($comp->fields['groups_id'] != $item->getField('groups_id')))) {

               if ($CFG_GLPI["is_user_autoupdate"]) {
                  $updates['users_id'] = $comp->fields['users_id'];
               }
               if ($CFG_GLPI["is_group_autoupdate"]) {
                  $updates['groups_id'] = $comp->fields['groups_id'];
               }
               Session::addMessageAfterRedirect(
                  __('User or group updated. The connected items have been moved in the same values.'),
                  true);
            }

            if ($CFG_GLPI["is_contact_autoupdate"]
                && (($comp->fields['contact'] != $item->getField('contact'))
                    || ($comp->fields['contact_num'] != $item->getField('contact_num')))) {

               $updates['contact']     = addslashes($comp->fields['contact']);
               $updates['contact_num'] = addslashes($comp->fields['contact_num']);
               Session::addMessageAfterRedirect(
                  __('Alternate username updated. The connected items have been updated using this alternate username.'),
                  true);
            }

            if (($CFG_GLPI["state_autoupdate_mode"] < 0)
                && ($comp->fields['states_id'] != $item->getField('states_id'))) {

               $updates['states_id'] = $comp->fields['states_id'];
               Session::addMessageAfterRedirect(
                     __('Status updated. The connected items have been updated using this status.'),
                     true);
            }

            if (($CFG_GLPI["state_autoupdate_mode"] > 0)
                && ($item->getField('states_id') != $CFG_GLPI["state_autoupdate_mode"])) {

               $updates['states_id'] = $CFG_GLPI["state_autoupdate_mode"];
            }

            if (count($updates)) {
               $updates['id'] = $input['items_id'];
               $item->update($updates);
            }
         }
      }
      return $input;
   }


   /**
    * Actions done when item is deleted from the database
    * Overloaded to manage autoupdate feature
    *
    *@return nothing
   **/
   function cleanDBonPurge() {
      global $CFG_GLPI;

      if (!isset($this->input['_no_auto_action'])) {
         //Get the computer name
         $computer = new Computer();
         $computer->getFromDB($this->fields['computers_id']);

         //Get device fields
         if ($device = getItemForItemtype($this->fields['itemtype'])) {
            if ($device->getFromDB($this->fields['items_id'])) {

               if (!$device->getField('is_global')) {
                  $updates = array();
                  if ($CFG_GLPI["is_location_autoclean"] && $device->isField('locations_id')) {
                     $updates['locations_id'] = 0;
                  }
                  if ($CFG_GLPI["is_user_autoclean"] && $device->isField('users_id')) {
                     $updates['users_id'] = 0;
                  }
                  if ($CFG_GLPI["is_group_autoclean"] && $device->isField('groups_id')) {
                     $updates['groups_id'] = 0;
                  }
                  if ($CFG_GLPI["is_contact_autoclean"] && $device->isField('contact')) {
                     $updates['contact'] = "";
                  }
                  if ($CFG_GLPI["is_contact_autoclean"] && $device->isField('contact_num')) {
                     $updates['contact_num'] = "";
                  }
                  if (($CFG_GLPI["state_autoclean_mode"] < 0)
                      && $device->isField('states_id')) {
                     $updates['states_id'] = 0;
                  }

                  if (($CFG_GLPI["state_autoclean_mode"] > 0)
                      && $device->isField('states_id')
                      && ($device->getField('states_id') != $CFG_GLPI["state_autoclean_mode"])) {

                     $updates['states_id'] = $CFG_GLPI["state_autoclean_mode"];
                  }

                  if (count($updates)) {
                     $updates['id'] = $this->fields['items_id'];
                     $device->update($updates);
                  }
               }

               if (isset($this->input['_ocsservers_id'])) {
                  $ocsservers_id = $this->input['_ocsservers_id'];
               } else {
                  $ocsservers_id = OcsServer::getByMachineID($this->fields['computers_id']);
               }

               if ($ocsservers_id > 0) {
                  //Get OCS configuration
                  $ocs_config = OcsServer::getConfig($ocsservers_id);

                  //Get the management mode for this device
                  $mode    = OcsServer::getDevicesManagementMode($ocs_config,
                                                                 $this->fields['itemtype']);
                  $decoConf= $ocs_config["deconnection_behavior"];

                  //Change status if :
                  // 1 : the management mode IS NOT global
                  // 2 : a deconnection's status have been defined
                  // 3 : unique with serial
                  if (($mode >= 2)
                      && (strlen($decoConf) > 0)) {

                     //Delete periph from glpi
                     if ($decoConf == "delete") {
                        $tmp["id"] = $this->fields['items_id'];
                        $device->delete($tmp, 1);

                     //Put periph in trash
                     } else if ($decoConf == "trash") {
                        $tmp["id"] = $this->fields['items_id'];
                        $device->delete($tmp, 0);
                     }
                  }
               } // $ocsservers_id>0
            }
         }
      }
   }


   /**
   * Disconnect an item to its computer
   *
   * @param $item    CommonDBTM object: the Monitor/Phone/Peripheral/Printer
   *
   * @return boolean : action succeeded
   */
   function disconnectForItem(CommonDBTM $item) {
      global $DB;

      if ($item->getField('id')) {
         $query = "SELECT `id`
                   FROM `glpi_computers_items`
                   WHERE `itemtype` = '".$item->getType()."'
                         AND `items_id` = '".$item->getField('id')."'";
         $result = $DB->query($query);

         if ($DB->numrows($result) > 0) {
            $ok = true;
            while ($data = $DB->fetch_assoc($result)) {
               if ($this->can($data["id"],'w')) {
                  $ok &= $this->delete($data);
               }
            }
            return $ok;
         }
      }
      return false;
   }


   /**
    *
    * Print the form for computers or templates connections to printers, screens or peripherals
    *
    * @param $comp                     Computer object
    * @param $withtemplate    boolean  Template or basic item (default '')
    *
    * @return Nothing (call to classes members)
   **/
   static function showForComputer(Computer $comp, $withtemplate='') {
      global $DB, $CFG_GLPI;

      $target  = $comp->getFormURL();
      $ID      = $comp->fields['id'];
      $canedit = $comp->can($ID,'w');

      $items = array('Monitor', 'Peripheral', 'Phone', 'Printer');
      $datas = array();
      foreach ($items as $itemtype) {
         $item = new $itemtype();
         if ($item->canView()) {
            $query = "SELECT *
                      FROM `glpi_computers_items`
                      WHERE `computers_id` = '$ID'
                            AND `itemtype` = '".$itemtype."'";

            $result = $DB->query($query);
            if ($result) {
               $nb                         = $DB->numrows($result);
               $datas[$itemtype]['result'] = $result;
               $datas[$itemtype]['title']  = $item->getTypeName($nb);
            }
         }
      }

      if (count($datas)) {
         echo "<div class='spaced'><table class='tab_cadre_fixe'>";
         echo "<tr><th colspan='2'>".__('Direct connections')."</th></tr>";

         //echo "<tr class='tab_bg_1'>";
         $items_displayed = 0;
         $nbperline       = 2;
         foreach ($datas as $itemtype => $data) {
            $used = array();

            // Line change
            if ($items_displayed%$nbperline == 0) {
               // Begin case
               if ($items_displayed != 0) {
                  echo "</tr>";
               }
               echo "<tr>";
               $count            = 0;
               $header_displayed = 0;
               foreach ($datas as $tmp_data) {
                  if (($count >= $items_displayed)
                      && ($header_displayed < $nbperline)) {
                     echo "<th>".$tmp_data['title']."</th>";
                     $header_displayed++;
                  }
                  $count++;
               }
               // Add header if line not complete
               while ($header_displayed%$nbperline != 0) {
                  echo "<th>&nbsp;</th>";
                  $header_displayed++;
               }
               echo "</tr>";
            }
            echo "<tr class='tab_bg_1'>";
            echo "<td class='center'>";

            $resultnum = $DB->numrows($data['result']);
            $item      = new $itemtype();
            if ($resultnum > 0) {
               echo "<table width='100%'>";
               for ($i=0; $i < $resultnum; $i++) {
                  $tID    = $DB->result($data['result'], $i, "items_id");
                  $connID = $DB->result($data['result'], $i, "id");

                  $item->getFromDB($tID);
                  $used[$tID] = $tID;

                  echo "<tr ".($item->isDeleted()?"class='tab_bg_2_2'":"").">";
                  echo "<td class='center'><span class='b'>".$item->getLink()."</span>";
                  echo " - ".Dropdown::getDropdownName("glpi_states", $item->getField('state'));
                  echo "</td><td>".$item->getField('serial');
                  echo "</td><td>".$item->getField('otherserial');
                  echo "</td><td>";
                  if ($canedit
                      && (empty($withtemplate) || ($withtemplate != 2))) {
                     echo "<td class='center b'>";
                     echo "<a href=\"".$CFG_GLPI["root_doc"].
                            "/front/computer.form.php?computers_id=$ID&amp;id=$connID&amp;" .
                            "disconnect=1&amp;withtemplate=".$withtemplate."\">";
                     echo __('Disconnect')."</a></td>";
                  }
                  echo "</tr>";
               }
               echo "</table>";

            } else {
               switch ($itemtype) {
                  case 'Printer' :
                     _e('No connected printer');
                     break;

                  case 'Monitor' :
                     _e('No connected monitor');
                     break;

                  case 'Peripheral' :
                     _e('No connected device');
                     break;

                  case 'Phone' :
                     _e('No connected phone');
                     break;
               }
               echo "<br>";
            }
            if ($canedit) {
               if (empty($withtemplate) || ($withtemplate != 2)) {
                  echo "<form method='post' action=\"$target\">";
                  echo "<input type='hidden' name='computers_id' value='$ID'>";
                  echo "<input type='hidden' name='itemtype' value='".$itemtype."'>";
                  if (!empty($withtemplate)) {
                     echo "<input type='hidden' name='_no_history' value='1'>";
                  }
                  self::dropdownConnect($itemtype, 'Computer', "items_id",
                                        $comp->fields["entities_id"], $withtemplate, $used);
                  echo "<input type='submit' name='connect' value=\""._sx('button', 'Connect')."\"
                         class='submit'>";
                  echo "</form>";
               }
            }
            echo "</td>";
            $items_displayed++;
         }
         while ($items_displayed%$nbperline != 0) {
            echo "<td>&nbsp;</td>";
            $items_displayed++;
         }
         echo "</tr>";
         echo "</table></div>";
      }
   }


   /**
    * Prints a direct connection to a computer
    *
    * @param $item                     CommonDBTM object: the Monitor/Phone/Peripheral/Printer
    * @param $withtemplate    integer  withtemplate param (default '')
    *
    * @return nothing (print out a table)
   **/
   static function showForItem(CommonDBTM $item, $withtemplate='') {
      // Prints a direct connection to a computer
      global $DB;

      $comp   = new Computer();
      $target = $comp->getFormURL();
      $ID     = $item->getField('id');

      if (!$item->can($ID,"r")) {
         return false;
      }
      $canedit = $item->can($ID,"w");

      // Is global connection ?
      $global  = $item->getField('is_global');

      $used    = array();
      $compids = array();
      $crit    = array('FIELDS'   => array('id', 'computers_id'),
                       'itemtype' => $item->getType(),
                       'items_id' => $ID);
      foreach ($DB->request('glpi_computers_items', $crit) as $data) {
         $compids[$data['id']] = $data['computers_id'];
      }

      echo "<div class='spaced'><table width='50%' class='tab_cadre_fixe'>";
      echo "<tr><th colspan='2'>".__('Direct connections')."</th></tr>";

      if (count($compids) > 0) {
         foreach ($compids as $key => $compid) {
            $comp->getFromDB($compid);
            echo "<tr><td class='b tab_bg_1".($comp->getField('is_deleted')?"_2":"")."'>";
            printf(__('%1$s: %2$s'), __('Computer'), $comp->getLink());
            echo "</td><td class='tab_bg_2".($comp->getField('is_deleted')?"_2":"")." center b'>";
            if ($canedit) {
               echo "<a href=\"$target?disconnect=1&amp;computers_id=$compid&amp;id=$key\">".
                      __('Disconnect')."</a>";
            } else {
               echo "&nbsp;";
            }
            $used[$compid] = $compid;
         }

      } else {
         echo "<tr><td class='tab_bg_1 b'><i>".__('Not connected')."</i></td>";
         echo "<td class='tab_bg_2' class='center'>";
         if ($canedit) {
            echo "<form method='post' action=\"$target\">";
            echo "<input type='hidden' name='items_id' value='$ID'>";
            echo "<input type='hidden' name='itemtype' value='".$item->getType()."'>";
            if ($item->isRecursive()) {
               self::dropdownConnect('Computer', $item->getType(), "computers_id",
                                     getSonsOf("glpi_entities", $item->getEntityID()), 0, $used);
            } else {
               self::dropdownConnect('Computer', $item->getType(), "computers_id",
                                     $item->getEntityID(), 0, $used);
            }
            echo "<input type='submit' name='connect' value=\""._sx('button', 'Connect')."\"
                   class='submit'>";
            echo "</form>";
         } else {
            echo "&nbsp;";
         }
      }

      if ($global
          && (count($compids) > 0)) {
         echo "</td></tr>";
         echo "<tr><td class='tab_bg_1'>&nbsp;</td>";
         echo "<td class='tab_bg_2' class='center'>";
         if ($canedit) {
            echo "<form method='post' action=\"$target\">";
            echo "<input type='hidden' name='items_id' value='$ID'>";
            echo "<input type='hidden' name='itemtype' value='".$item->getType()."'>";
            if ($item->isRecursive()) {
               self::dropdownConnect('Computer', $item->getType(), "computers_id",
                                     getSonsOf("glpi_entities", $item->getEntityID()), 0, $used);
            } else {
               self::dropdownConnect('Computer', $item->getType(), "computers_id",
                                     $item->getEntityID(), 0, $used);
            }
            echo "<input type='submit' name='connect' value=\""._sx('button', 'Connect')."\"
                   class='submit'>";
            echo "</form>";
         } else {
            echo "&nbsp;";
         }
      }
      echo "</td></tr>";
      echo "</table></div>";
   }


   /**
    * Unglobalize an item : duplicate item and connections
    *
    * @param $item   CommonDBTM object to unglobalize
   **/
   static function unglobalizeItem(CommonDBTM $item) {
      global $DB;

      // Update item to unit management :
      if ($item->getField('is_global')) {
         $input = array('id'        => $item->fields['id'],
                        'is_global' => 0);
         $item->update($input);

         // Get connect_wire for this connection
         $query = "SELECT `glpi_computers_items`.`id`
                   FROM `glpi_computers_items`
                   WHERE `glpi_computers_items`.`items_id` = '".$item->fields['id']."'
                         AND `glpi_computers_items`.`itemtype` = '".$item->getType()."'";
         $result = $DB->query($query);

         if ($data = $DB->fetch_assoc($result)) {
            // First one, keep the existing one

            // The others = clone the existing object
            unset($input['id']);
            $conn = new self();
            while ($data = $DB->fetch_assoc($result)) {
               $temp = clone $item;
               unset($temp->fields['id']);
               if ($newID=$temp->add($temp->fields)) {
                  $conn->update(array('id'       => $data['id'],
                                      'items_id' => $newID));
               }
            }
         }
      }
   }


   /**
   * Make a select box for connections
   *
   * @param $itemtype               type to connect
   * @param $fromtype               from where the connection is
   * @param $myname                 select name
   * @param $entity_restrict        Restrict to a defined entity (default = -1)
   * @param $onlyglobal             display only global devices (used for templates) (default 0)
   * @param $used             array Already used items ID: not to display in dropdown
   *
   * @return nothing (print out an HTML select box)
   */
   static function dropdownConnect($itemtype, $fromtype, $myname, $entity_restrict=-1,
                                   $onlyglobal=0, $used=array()) {
      global $CFG_GLPI;

      $rand     = mt_rand();
      $use_ajax = false;
      if ($CFG_GLPI["use_ajax"]) {
         $nb = 0;
         if ($entity_restrict >= 0) {
            $nb = countElementsInTableForEntity(getTableForItemType($itemtype), $entity_restrict);
         } else {
            $nb = countElementsInTableForMyEntities(getTableForItemType($itemtype));
         }
         if ($nb > $CFG_GLPI["ajax_limit_count"]) {
            $use_ajax = true;
         }
      }

      $params = array('searchText'      => '__VALUE__',
                      'fromtype'        => $fromtype,
                      'idtable'         => $itemtype,
                      'myname'          => $myname,
                      'onlyglobal'      => $onlyglobal,
                      'entity_restrict' => $entity_restrict,
                      'used'            => $used);

      $default = "<select name='$myname'><option value='0'>".Dropdown::EMPTY_VALUE."</option>
                  </select>\n";
      Ajax::dropdown($use_ajax, "/ajax/dropdownConnect.php", $params, $default, $rand);

      return $rand;
   }


   /**
    * @see inc/CommonGLPI::getTabNameForItem()
   **/
   function getTabNameForItem(CommonGLPI $item, $withtemplate=0) {

      // can exists for Template
      if ($item->can($item->getField('id'),'r')) {
         switch ($item->getType()) {
            case 'Phone' :
            case 'Printer' :
            case 'Peripheral' :
            case 'Monitor' :
               if (Session::haveRight('computer', 'r')) {
                  if ($_SESSION['glpishow_count_on_tabs']) {
                     return self::createTabEntry(_n('Connection','Connections',2),
                                                 self::countForItem($item));
                  }
                  return _n('Connection','Connections',2);
               }
               break;

            case 'Computer' :
               if (Session::haveRight('phone', 'r')
                   || Session::haveRight('printer', 'r')
                   || Session::haveRight('peripheral', 'r')
                   || Session::haveRight('monitor', 'r')) {
                  if ($_SESSION['glpishow_count_on_tabs']) {
                     return self::createTabEntry(_n('Connection','Connections',2),
                                                 self::countForComputer($item));
                  }
                  return _n('Connection','Connections',2);
               }
               break;
         }
      }
      return '';
   }


   /**
    * @param $item         CommonGLPI object
    * @param $tabnum       (default 1)
    * @param $withtemplate (default 0)
    */
   static function displayTabContentForItem(CommonGLPI $item, $tabnum=1, $withtemplate=0) {

      switch ($item->getType()) {
         case 'Phone' :
         case 'Printer' :
         case 'Peripheral' :
         case 'Monitor' :
            self::showForItem($item);
            return true;

         case 'Computer' :
            self::showForComputer($item);
            return true;
      }
   }
}
?>