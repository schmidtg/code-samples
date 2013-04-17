<?php
/**
 * Chron
 *
 * Simple alerting and logging library to keep
 * track of events during a cron script run
 *
 * @author      Graham Schmidt
 * @created     2013-02-07
 */

class Chron
{
    /**
    * Log messages
    */
    public static function oLog($message, $ret = FALSE)
    {
        global $olog_outt;
        $olog_outt .= $message."\n";

        if ($ret)
            return $olog_outt;
    }

    /**
    * Log a list of alerts to be e-mailed
    */
    public static function alertLog($message, $ret = FALSE)
    {
        global $alog_out;
        if (!empty($message)) {
            $alog_out[] = $message;
        }

        if ($ret)
            return $alog_out;
    }

    /**
    * Log an error to the oLog and alertLog
    */
    public static function oaLog($message = '')
    {
        if ($message == '')
            return;

        self::oLog($message);
        self::alertLog($message);
    }

    /**
    * Log an error in the error_log and oLog
    */
    public static function oeLog($message = '')
    {
        if ($message == '')
            return;

        error_log($message);
        self::oLog($message);
    }

    /**
    * Log an error to the oLog, error_log and alertLog
    */
    public static function oaeLog($message = '')
    {
        if ($message == '')
            return;

        self::oeLog($message);
        self::alertLog($message);
    }

    /**
    * Write error log
    */
    public static function writeLog($id = 'hex')
    {
        $dir = "logs";
        self::createDirectory($dir);
        $filename = strtolower($id) . "_log_".date('YmdGhi').".txt";
        $o = self::oLog("Wrote log.", true);
        file_put_contents( $dir . "/" . $filename, $o );

        return array(
            dirname($_SERVER['SCRIPT_NAME']) . "/" . $dir . "/" . $filename, // url path
            realpath($dir . "/" . $filename),
            $filename // filename
        );
    }

    /**
     * Write the CSV output
     */
    public static function writeCSV($filename, $path, $data)
    {
        // create directory if it doesn't exist
        $dir = "csv";
        self::createDirectory($dir);

        $path .= "/{$dir}/";
        $full_path = $path . $filename;

        $fp = fopen($full_path, 'w');
        if ($fp == NULL)
        {
            self::oaLog("Could not create CSV: {$full_path}");
            self::finished("system");
        }

        // write file to CSV
        foreach ($data as $fields) {
            fputcsv($fp, $fields);
        }
        self::oLog("Wrote the CSV");

        fclose($fp);

        return array(
            $filename,
            $path
        );
    }

    /**
     * Create a directory
     */
    public static function createDirectory($path)
    {
        if ($path == '') {
            throw new Exception("Please specify a directory.");
        }

        if (!is_dir($path)) {
            if (!mkdir($path, 0775)) {
                die("Failed to create archive directory.");
            }
            self::oLog("Folder available: $path");
        }
        return true;
    }

    /**
    * Mail alerts
    */
    public static function mailAlerts($email_address = '', $email_prefix = "HEX", $subject = 'alerts')
    {
        if (empty($email_address)) {
            return;
        }

        // get list of alerts to send
        $alog = self::alertLog("", true);
        $body = (empty($alog) ? 'No alerts to report.' : implode("\n", $alog));

        // count alerts, exclude 'View log' link
        $count = count($alog);
        if ( stristr($body, 'View log file')) {
            $count = $count - 1;
        }

        $subject = '[' . $email_prefix . '] ' . $count . " " . $subject . " - " . date('Y-m-d G:i:s');

        // mail alerts
        mail($email_address, $subject, $body);
    }

    /**
     * Mail the CSV
     * (mail attachment wrapper)
     */
    public static function mailCSV($filenames = array(), $path, $subject, $message, $mailto, $from_mail, $from_name, $replyto)
    {
        // params order: $files (array), $path, $mailto, $from_mail, $from_name, $replyto, $subject, $message
        return Chron::mailAttachments(
            $filenames,
            $path,
            $mailto,
            $from_mail,
            $from_name,
            $replyto,
            $subject,
            "See attached CSVs"
        );
    }

    /**
     * Mail e-mail with attachment
     * e.g. http://stackoverflow.com/questions/9519588/send-php-html-mail-with-attachments
     */
    public static function mailAttachments($files, $path, $mailto, $from_mail, $from_name, $replyto, $subject, $message) {
        $uid = md5(uniqid(time()));

        $header = "From: ".$from_name." <".$from_mail.">\r\n";
        $header .= "Reply-To: ".$replyto."\r\n";
        $header .= "MIME-Version: 1.0\r\n";
        $header .= "Content-Type: multipart/mixed; boundary=\"".$uid."\"\r\n\r\n";
        $header .= "This is a multi-part message in MIME format.\r\n";
        $header .= "--".$uid."\r\n";
        $header .= "Content-type:text/html; charset=iso-8859-1\r\n";
        $header .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
        $header .= $message."\r\n\r\n";

            foreach ($files as $filename) {

                $file = $path.$filename;
                $name = basename($file);
                $file_size = filesize($file);
                $handle = fopen($file, "r");
                $content = fread($handle, $file_size);
                fclose($handle);
                $content = chunk_split(base64_encode($content));

                $header .= "--".$uid."\r\n";
                $header .= "Content-Type: application/octet-stream; name=\"".$filename."\"\r\n"; // use different content types here
                $header .= "Content-Transfer-Encoding: base64\r\n";
                $header .= "Content-Disposition: attachment; filename=\"".$filename."\"\r\n\r\n";
                $header .= $content."\r\n\r\n";
            }

        $header .= "--".$uid."--";

        return mail($mailto, $subject, "", $header);
    }

    /**
    * Finish logging and send alerts
    */
    public static function finish($name = 'HEX', $email_address = 'graham@hungryfishmedia.com')
    {
        if (empty($email_address)) {
            return;
        }

        echo self::oLog("[END] {$name} script.", true);
        self::mailAlerts($email_address, $name);
    }

    /**
     * Finish loggin, send alerts, and halt execution
     */
    public static function finished($name = 'General', $email_address = 'graham@hungryfishmedia.com')
    {
        self::finish($name, $email_address);
        exit("Stopped script execution");
    }

}
