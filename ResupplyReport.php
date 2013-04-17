<?php
/**
 * Resupply Reminder Report
 *
 * Jira ticket
 * https://hungryfishmedia.jira.com/browse/tech-4919
 *
 * Google Doc
 * https://docs.google.com/a/hungryfishmedia.com/spreadsheet/ccc?key=0An5sG1ZluV4GdFRocFpNM21PRldSeThLZHB0QmVVakE#gid=0
 *
 * Dependencies
 *  ItemsHelper
 *  Chron
 *
 * @author      Graham Schmidt
 * @created     2013-03-18
 */

class ResupplyReport {

    private $bucket = array();
    private $o_log = '';

    public function __construct($date_start, $date_end)
    {
        self::generateBuckets($date_start, $date_end);
    }

    public function olog($m)
    {
    	$this->o_log .= $m;
    }

    public function pLog()
    {
    	return $this->o_log;
    }

    public function buildMembersAll($date_start, $date_end)
    {
        // validation
        self::validateDates($date_start, $date_end);

        $statuses = "'Canceled', 'Pending Resupply', 'Active', 'Awaiting Processing', 'Completed'";

        $sql = "
            SELECT
                        od.order_id AS order_id
                        , from_unixtime(od.date_ordered) as date_ordered
                        , from_unixtime(od.next_bill) AS next_bill
                        , NOW() as now
                        , od.source
                        , od.status as status
                        , ld.first_name
                        , ld.last_name
                        , ld.email_address
                        , od.recur_skus as sku_str
                        , od.onetime_skus
                        , od.product_id
            FROM        orders od
            LEFT JOIN   leads ld USING (lead_id)
            WHERE 1
            AND         od.date_ordered >= unix_timestamp('{$date_start}')
            AND         od.date_ordered <= unix_timestamp('{$date_end}')
            AND         od.status IN ({$statuses})
            AND         od.source = 'Resupply Reminder'
            AND         (ld.email_address NOT LIKE '%hungryfish%')
            ORDER BY    od.product_id, od.recur_skus
        ";

        global $finder_db;

        try
        {
            $res = $finder_db->query($sql);
            $res->tossIfNoRows();

            self::oLog($res->getSQL());

            return $res->fetchAllRows();
        }
        catch (fNoRowsException $e)
        {
            return null;
        }
        catch (Exception $e)
        {
            Chron::oLog($e->getMessage());
            return $e->getMessage();
        }
    }

    /**
     * Group result set in SKUs
     */
    public function generateBuckets($date_start, $date_end)
    {
        $rows = self::buildMembersAll($date_start, $date_end);
        $res = array();
        foreach ($rows as $row)
        {
            // count each order for the product
            self::addToBucket('pid', $row['product_id']);

            // need to count a person for each SKU on their order
            $skus = self::skuToArray($row['sku_str']);
            if (!empty($skus))
            {
                foreach ($skus as $sku => $qty)
                {
                    self::addToBucket('sku', $sku);

                    // active members
                    if ($row['next_bill'] > $row['now'])
                    {
                        self::addToBucket('active_members_sku', $row['product_id'] . "-" . $sku);
                    }

                    // canceled members
                    if ($row['status'] == 'Canceled')
                    {
                        self::addToBucket('canceled', $row['product_id'] . "-" . $sku);
                    }

                    // completed a purchase or are on continuity
                    if ( in_array($row['status'], array('Active')) )
                    {
                        self::addToBucket('sku_qty_purchased', $sku, $qty);
                        self::addToBucket('purchased', $row['product_id'] . "-" . $sku);
                    }
                }
            }

            // for completed purchases (no recur SKUs)
            $onetime_skus = self::skuToArray($row['onetime_skus']);
            if (empty($skus) 
            	&& !empty($onetime_skus)
            ) {
                foreach ($onetime_skus as $sku => $qty)
                {
                    // completed a purchase or are on continuity
                    if ( in_array($row['status'], array('Awaiting Processing', 'Completed')) )
                    {
                        self::addToBucket('sku_qty_purchased', $sku, $qty);                        
                        self::addToBucket('purchased', $row['product_id'] . "-" . $sku);
                    }
                }
            }
        }
    }

    /**
     * Return a bucket count
     */
    public function getBucket($type)
    {
        return (isset($this->bucket[$type])
            ? $this->bucket[$type]
            : null
        );
    }

    /**
    * Return the overal report data structure
    */
    public function getReportData()
    {
        $data = array();
        $ppoints = array();
        if ($pids = $this->getBucket('pid'))
        {
            $active_members_sku = $this->getBucket('active_members_sku');

            foreach ($pids as $pid => $count)
            {
                unset($pp);
                $pp = ItemsHelper::getRecurPricePoints($pid, 1, true);
                $ppoints[$pid] = $pp[$pid];
            }

            $skus = $this->getBucket('sku');
            $sku_qty_purchased = $this->getBucket('sku_qty_purchased');
            $purchased = $this->getBucket('purchased');
            $canceled = $this->getBucket('canceled');

            // create main data struct
            foreach ($ppoints as $pid => $price_points)
            {
                foreach ($price_points as $sku => $sku_details)
                {
                    $data[$pid][$sku] = array(
                        'total_members'        => $skus[$sku],
                        'purchased'            => $purchased[$pid.'-'.$sku],
                        'active_members'       => $active_members_sku[$pid.'-'.$sku],
                        'canceled'             => $canceled[$pid.'-'.$sku],
                        'sku'                  => $sku,
                        'price_point'          => $price_points[$sku]['price_point'],
                        'price'                => $price_points[$sku]['price'],
                        'revenue'              => ($purchased[$pid.'-'.$sku] * $price_points[$sku]['price'] * $sku_qty_purchased[$sku]),
                    );

                    // calculate totals for each $pid here
                    (!isset($sku_totals[$pid]['total_members']) ? $sku_totals[$pid]['total_members'] = 0 : '');
                    (!isset($sku_totals[$pid]['purchased']) ? $sku_totals[$pid]['purchased'] = 0 : '');
                    (!isset($sku_totals[$pid]['active_members']) ? $sku_totals[$pid]['active_members'] = 0 : '');
                    (!isset($sku_totals[$pid]['canceled']) ? $sku_totals[$pid]['canceled'] = 0 : '');
                    (!isset($sku_totals[$pid]['revenue']) ? $sku_totals[$pid]['revenue'] = 0 : '');

                    $sku_totals[$pid]['total_members'] += $skus[$sku];
                    $sku_totals[$pid]['purchased'] += $purchased[$pid.'-'.$sku];
                    $sku_totals[$pid]['active_members'] += $active_members_sku[$pid.'-'.$sku];
                    $sku_totals[$pid]['canceled'] += $canceled[$pid.'-'.$sku];
                    $sku_totals[$pid]['revenue'] += ($purchased[$pid.'-'.$sku] * $price_points[$sku]['price'] * $sku_qty_purchased[$sku]);
                }

                $data[$pid]['totals'] = array(
                    'name' => 'TOTAL',
                    'total_members' => $sku_totals[$pid]['total_members'],
                    'purchased' => $sku_totals[$pid]['purchased'],
                    'active_members' => $sku_totals[$pid]['active_members'],
                    'canceled' => $sku_totals[$pid]['canceled'],
                    'revenue' => $sku_totals[$pid]['revenue'],
                );
            }
        }

        return $data;
    }

    /**
     * Add a count to a bucket
     */
    private function addToBucket($b_key = 'sku', $key, $inc = 1)
    {
        if (empty($key))
            return;

        if (!isset($this->bucket[$b_key][$key]))
        {
            $this->bucket[$b_key][$key] = 0;
        }
        $this->bucket[$b_key][$key] += $inc;
    }

    /**
     * Turn a SKU string into an array
     */
    private function skuToArray($sku_str)
    {
        if (empty($sku_str))
        {
            return null;
        }

        $skus = array();
        $skus_x = explode('|', $sku_str);
        foreach ($skus_x as $sku_x)
        {
            // skip empty element at end
            if (empty($sku_x))
            {
                continue;
            }

            $skq = explode(',', $sku_x);
            // key -> SKU
            // val -> Quantity
            $skus[$skq[0]] = (isset($skq[1]) ? $skq[1] : '');
        }

        return $skus;
    }

    /**
     * Validate date params
     */
    private function validateDates($date_start, $date_end)
    {
        // validate dates
        try {
            // properly formed dates
            self::validDate($date_start, 'Start');
            self::validDate($date_end, 'End');

            // end date greater than start date
            if ($date_end < $date_start)
            {
                throw new Exception("The end date must be ahead of the start date.");
            }

            // dates can't be more than 4 months apart
            if ( (strtotime($date_end) - strtotime($date_start)) > 10368000 )
            {
                throw new Exception("Date range limited to 4 months.");
            }
        }
        catch (Exception $e)
        {
            throw new Exception($e->getMessage());
        }
    }

    /**
     * Validate a date string
     */
    private function validDate($date, $type = 'Start')
    {
        // date end validation
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new Exception("Please provide a {$type} date. It must be in the format yyyy-mm-dd.");
        }
    }
}
