<?php
/**
 * CronRun.php
 *
 * @author      Graham Schmidt
 * @created     2012-09-25
 *
 * Updates the last time a cron was run.
 * Gets the last time a cron was run.
 * Gets meta-data on cron scripts.
 */

class CronRun
{

    public static function finished( $product_id = 0, $cron_script_id = 0, $collection_days = array() )
    {
        // Sanity check
        if ( $product_id == 0
            || $cron_script_id == 0
            || empty($collection_days)
        ) {
            error_log("Problem with inputs for Cron Run.");
            return false;
        }

        global $finder_db;

        // Sort the collection days (earlist to latest)
        sort($collection_days);
        $interval_start = array_shift($collection_days);
        $interval_end = $interval_start;
        // set end date if available
        if (!empty($collection_days)) {
            $interval_end = array_pop($collection_days);
        }

        $sql = "
            INSERT INTO cron_runs SET
                product_id = %i
                , cron_script_id = %i
                , interval_start = %s
                , interval_end = %s
            ";

        try {
            $results = $finder_db->query(
                $sql
                , $product_id
                , $cron_script_id
                , $interval_start
                , $interval_end
            );

        } catch (Exception $e) {
            self::notifyOwners("Cron could not insert start time. ".$e->getMessage());
        }

    }

    /**
     * Get the last capture time for a product id
     */
    public static function getLastCaptureTime($product_id = 0, $cron_name = 'capture', $use_post_date = false)
    {
        // Sanity check
        if ( $product_id == 0 ) {
            error_log("product id is 0.");
            return false;
        }
        if ( $cron_name == '' ) {
            error_log("need a cron name");
            return false;
        }

        // calculate last interval based on authorization post_date (recommended)
        // OR anticipated cut-off date for date_order
        $field_type = ($use_post_date
            ? 'SUBSTRING(MAX(interval_end), 1, 10) as last_post_date'
            : 'MAX(unix_timestamp(interval_end)) + 79200 as last_captured_time'
        );

        global $finder_db;

        // Add 79200 to get the end of the interval
        // (the input interval for interval_end is actually the beginning of the day)
        $sql = "
            SELECT
                        {$field_type}
            FROM        cron_runs
            LEFT JOIN   cron_scripts USING (cron_script_id)
            WHERE       1
            AND         product_id = %i
            AND         name_of_cron = %s
            ";
        try {
            $results = $finder_db->query(
                $sql
                , $product_id
                , $cron_name
            );
            fSession::set('cronrun', $results->getSQL());
            $results->tossIfNoRows();
            return $results->fetchScalar();

        } catch (fNoRowsException $e) {
            return false;

        } catch (Exception $e) {
            self::notifyOwners("Problem found. ".$e->getMessage());
        }
    }

    /**
     * Get the last interval-end for billing
     */
    public static function getLastPostdate($product_id = 0, $cron_name = 'capture')
    {
        return self::getLastCaptureTime($product_id, $cron_name, true);
    }

    /**
     * Notify the script owner of a problem
     */
    public static function notifyOwners($message = '')
    {
        mail(
            "graham@hungryfishmedia.com"
            , "Cron run problem"
            , $message
        );
    }
}
