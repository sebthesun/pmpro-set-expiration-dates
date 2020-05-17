<?php

/*
Plugin Name: Paid Memberships Pro - Set Expiration Dates Add On
Plugin URI: http://www.paidmembershipspro.com/wp/pmpro-set-expiration-dates/
Description: Set a specific expiration date (e.g. 2013-12-31) for a PMPro membership level or discount code.
Version: .5.1
Author: Stranger Studios
Author URI: http://www.strangerstudios.com
*/

/*
	This first set of functions adds our fields to the edit membership levels page
 */
function pmpro_set_expiration_date_load_textdomain()
{
        //get the locale

        $locale = apply_filters("plugin_locale", get_locale(), "pmpro-set-expiration-dates");
        $mofile = "pmpro-set-expiration-dates-" . $locale . ".mo";

        //paths to local (plugin) and global (WP) language files
        $mofile_local  = dirname(__FILE__)."/languages/" . $mofile;

        //load local second
        load_textdomain("pmpro-set-expiration-dates", $mofile_local);

}
add_action("init", "pmpro_set_expiration_date_load_textdomain");

//add level cost text field to level price settings
function pmprosed_pmpro_membership_level_after_other_settings()
{
    $level_id = intval($_REQUEST['edit']);
    if ($level_id > 0)
        $set_expiration_date = pmpro_getSetExpirationDate($level_id);
    else
        $set_expiration_date = "";
    ?>
	    <h3 class="topborder"><?= __('Set Expiration Date','pmpro-set-expiration-dates') ?></h3>
	    <p><?= __('To have this level expire on a specific date, enter it below in YYYY-MM-DD format. <strong>Note:</strong> You
	    must also set an expiration date above (e.g. 1 Year) which will be overwritten by the value below.','pmpro-set-expiration-dates') ?></p>
    <table>
        <tbody class="form-table">
        <tr>
	<th scope="row" valign="top"><label for="set_expiration_date"><?= __('Expiration Date:','pmpro-set-expiration-dates') ?></label></th>
            <td>
                <input type="text" name="set_expiration_date" value="<?php echo esc_attr($set_expiration_date); ?>"/>
                <br/>
		<small><?= __('YYYY-MM-DD format. Enter "Y" for current year, "Y2" for next year. M, M2 for current/next
		month.','pmpro-set-expiration-dates') ?>
                </small>
            </td>
        </tr>
        </tbody>
    </table>
    <?php
}
add_action("pmpro_membership_level_after_other_settings", "pmprosed_pmpro_membership_level_after_other_settings");

//save level cost text when the level is saved/added
function pmprosed_pmpro_save_membership_level($level_id)
{
    pmpro_saveSetExpirationDate($level_id, $_REQUEST['set_expiration_date']);            //add level cost text for this level
}
add_action("pmpro_save_membership_level", "pmprosed_pmpro_save_membership_level");

/*
	Function to replace Y and M/etc with actual dates
*/
function pmprosed_fixDate($set_date, $current_date = null)
{
    // handle lower-cased y/m values.
    $set_date = strtoupper($set_date);

    // Change "M-" and "Y-" to "M1-" and "Y1-".
    $set_date = preg_replace('/Y-/', 'Y1-', $set_date);
    $set_date = preg_replace('/M-/', 'M1-', $set_date);

    // Get number of months and years to add.
    $m_pos = stripos( $set_date, 'M' );
    $y_pos = stripos( $set_date, 'Y' );
    if($m_pos !== false) {
        $add_months = intval($set_date[$m_pos + 1]);
    }
    if($y_pos !== false) {
        $add_years = intval($set_date[$y_pos + 1]);
    }

    // Allow new dates to be set from a custom date.
    if(empty($current_date)) $current_date = current_time( 'timestamp' );

    // Get current date parts.
    $current_y = intval(date('Y', $current_date));
    $current_m = intval(date('m', $current_date));
    $current_d = intval(date('d', $current_date));

    // Get set date parts.
    $date_parts = explode( '-', $set_date);
    $set_y = intval($date_parts[0]);
    $set_m = intval($date_parts[1]);
    $set_d = intval($date_parts[2]);

    // Get temporary date parts.
    $temp_y = $set_y > 0 ? $set_y : $current_y;
    $temp_m = $set_m > 0 ? $set_m : $current_m;
    $temp_d = $set_d;

    // Add months.
    if(!empty($add_months)) {
        for($i = 0; $i < $add_months; $i++) {
            // If "M1", only add months if current date of month has already passed.
            if(0 == $i) {
                if($temp_d < $current_d) {
                    $temp_m++;
                    $add_months--;
                }
            } else {
                $temp_m++;
            }

            // If we hit 13, reset to Jan of next year and subtract one of the years to add.
            if($temp_m == 13) {
                $temp_m = 1;
                $temp_y++;
                $add_years--;
            }
        }
    }

    // Add years.
    if(!empty($add_years)) {
        for($i = 0; $i < $add_years; $i++) {
            // If "Y1", only add years if current date has already passed.
            if(0 == $i) {
                $temp_date = strtotime(date("{$temp_y}-{$temp_m}-{$temp_d}"));
                if($temp_date < $current_date) {
                    $temp_y++;
                    $add_years--;
                }
            } else {
                $temp_y++;
            }
        }
    }
    
    // Pad dates if necessary.
    $temp_m = str_pad($temp_m, 2, '0', STR_PAD_LEFT);
    $temp_d = str_pad($temp_d, 2, '0', STR_PAD_LEFT);

    // Put it all together.
    $set_date = date( "{$temp_y}-{$temp_m}-{$temp_d}" );

    // Make sure we use the right day of the month for dates > 28
    // From: http://stackoverflow.com/a/654378/1154321
    $dotm = pmpro_getMatches('/\-([0-3][0-9]$)/', $set_date, true);
    if ( $temp_m == '02' && intval($dotm) > 28 || intval($dotm) > 30 ) {
        $set_date = date('Y-m-t', strtotime(substr($set_date, 0, 8) . "01"));
    }

    return $set_date;
}

/*
	Update expiration date of level at checkout.
*/
function pmprosed_pmpro_checkout_level($level, $discount_code_id = null)
{
    global $wpdb;

	//in case the $level object has been cleared out already/etc
	if(empty($level) || empty($level->id))
		return $level;
	
    if (empty($discount_code_id) && !empty($_REQUEST['discount_code'])) {
        //get discount code passed in
        $discount_code = preg_replace("/[^A-Za-z0-9\-]/", "", $_REQUEST['discount_code']);

        if (!empty($discount_code)) {
            $discount_code_id = $wpdb->get_var("SELECT id FROM $wpdb->pmpro_discount_codes WHERE code = '" . esc_sql($discount_code) . "' LIMIT 1");
        } else {
            $discount_code_id = NULL;
        }
    }

    //does this level have a set expiration date?
    $set_expiration_date = pmpro_getSetExpirationDate($level->id, $discount_code_id);

    //check for Y
    if (strpos($set_expiration_date, "Y") !== false) {
        $used_y = true;
    }

    if (!empty($set_expiration_date)) {
        //replace vars
        $set_expiration_date = pmprosed_fixDate($set_expiration_date);

        //how many days until expiration
        $todays_date = time();
        $time_left = strtotime($set_expiration_date) - $todays_date;
        if ($time_left > 0) {
            $days_left = ceil($time_left / (60 * 60 * 24));

            //update number and period
            $level->expiration_number = $days_left;
            $level->expiration_period = __("Day",'pmpro-set-expiration-dates');

            return $level;    //stop
        } elseif (!empty($used_y)) {
            $timestamp = strtotime($set_expiration_date);

            //add one year to expiration date
            $set_expiration_date = date("Y-m-d", mktime(0, 0, 0, date('m', $timestamp), date('d', $timestamp), date('Y', $timestamp) + 1));

            //how many days until expiration
            $time_left = strtotime($set_expiration_date) - $todays_date;
            $days_left = ceil($time_left / (60 * 60 * 24));

            //update number and period
            $level->expiration_number = $days_left;
            $level->expiration_period = __("Day",'pmpro-set-expiration-dates');

            return $level; //stop
        } else {
            //expiration already here, don't let people signup
            $level = NULL;

            return $level; //stop
        }
    }

    return $level;    //no change
}
add_filter("pmpro_checkout_level", "pmprosed_pmpro_checkout_level");
add_filter('pmpro_discount_code_level', 'pmprosed_pmpro_checkout_level', 10, 2);
add_filter('pmpro_ipnhandler_level', 'pmprosed_pmpro_checkout_level');

/*
	This function will save a the set expiration dates into wp_options.
*/
function pmpro_saveSetExpirationDate($level_id, $set_expiration_date, $code_id = NULL)
{
    if ($code_id) {
        $key = "pmprosed_" . $level_id . "_" . $code_id;
    } else {
        $key = "pmprosed_" . $level_id;
    }

    update_option($key, $set_expiration_date);
}

/*
	This function will return the expiration date for a level or discount code/level combo
*/
function pmpro_getSetExpirationDate($level_id, $code_id = NULL)
{
    if ($code_id) {
        $key = "pmprosed_" . $level_id . "_" . $code_id;
    } else {
        $key = "pmprosed_" . $level_id;
    }

    return get_option($key, "");
}


/*
	This next set of functions adds our field to the edit discount code page
*/
//add our field to level price settings
function pmprosed_pmpro_discount_code_after_level_settings($code_id, $level)
{
    $set_expiration_date = pmpro_getSetExpirationDate($level->id, $code_id);
    ?>
    <table>
        <tbody class="form-table">
        <tr>
            <td>
        <tr>
	<th scope="row" valign="top"><label for="set_expiration_date"><?= __('Expiration Date:','pmpro-set-expiration-dates') ?></label></th>
            <td>
                <input type="text" name="set_expiration_date[]" value="<?php echo esc_attr($set_expiration_date); ?>"/>
                <br/>
		<small><?= __('YYYY-MM-DD format. Enter "Y" for current year, "Y2" for next year. M, M2 for current/next month.
		Be sure to set an expiration date above as well.','pmpro-set-expiration-dates') ?>
                </small>
            </td>
        </tr>
        </td>
        </tr>
        </tbody>
    </table>
    <?php
}
add_action("pmpro_discount_code_after_level_settings", "pmprosed_pmpro_discount_code_after_level_settings", 10, 2);

//save level cost text for the code when the code is saved/added
function pmprosed_pmpro_save_discount_code_level($code_id, $level_id)
{
    $all_levels_a = $_REQUEST['all_levels'];                            //array of level ids checked for this code
    $set_expiration_date_a = $_REQUEST['set_expiration_date'];            //expiration dates for levels checked

    if (!empty($all_levels_a)) {
        $key = array_search($level_id, $all_levels_a);                //which level is it in the list?
        pmpro_saveSetExpirationDate($level_id, $set_expiration_date_a[$key], $code_id);
    }
}
add_action("pmpro_save_discount_code_level", "pmprosed_pmpro_save_discount_code_level", 10, 2);

/*
Function to add links to the plugin row meta
*/
function pmprosed_plugin_row_meta($links, $file)
{
    if (strpos($file, 'pmpro-set-expiration-dates.php') !== false) {
        $set_links = array(
            '<a href="' . esc_url('http://www.paidmembershipspro.com/add-ons/plugins-on-github/pmpro-expiration-date/') . '" title="' . esc_attr(__('View Documentation', 'pmpro')) . '">' . __('Docs', 'pmpro') . '</a>',
            '<a href="' . esc_url('http://paidmembershipspro.com/support/') . '" title="' . esc_attr(__('Visit Customer Support Forum', 'pmpro')) . '">' . __('Support', 'pmpro') . '</a>',
        );
        $links = array_merge($links, $set_links);
    }
    return $links;
}
add_filter('plugin_row_meta', 'pmprosed_plugin_row_meta', 10, 2);

/*
	Update expiration text on levels page.
*/
function pmprosed_pmpro_level_expiration_text($expiration_text, $level)
{
    $set_expiration_date = pmpro_getSetExpirationDate($level->id);

    if (!empty($set_expiration_date)) {
        $set_expiration_date = pmprosed_fixDate($set_expiration_date);
        $expiration_text = __("Membership expires on ",'pmpro-set-expiration-dates') . date(get_option('date_format'), strtotime($set_expiration_date, current_time('timestamp'))) . ".";
    }

    return $expiration_text;
}
add_filter('pmpro_level_expiration_text', 'pmprosed_pmpro_level_expiration_text', 10, 2);
