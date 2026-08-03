<?php
/*********************************************************************
    class.mailfetcher.php

    osTicket/Mail/Fetcher

    Peter Rotich <peter@osticket.com>, Kevin Thorne <kevin@osticket.com>
    Copyright (c)  osTicket
    http://www.osticket.com

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.

    vim: expandtab sw=4 ts=4 sts=4:
**********************************************************************/

namespace osTicket\Mail;

class Fetcher {
    private $account;
    private $mbox;
    private $api;

    function __construct(\MailboxAccount $account, $charset='UTF-8') {
        $this->account = $account;
        $this->mbox = $account->getMailBox();
        if (($folder = $this->getFetchFolder()))
            $this->mbox->selectFolder($folder);
    }

    function getEmailId() {
        return $this->account->getEmailId();
    }

    function getEmail() {
        return $this->account->getEmail();
    }

    function getEmailAddress() {
        return $this->account->getEmail()->getAddress();
    }

    function getMaxFetch() {
        return $this->account->getMaxFetch();
    }

    function getFetchFolder() {
        return $this->account->getFetchFolder();
    }

    function getArchiveFolder() {
        return $this->account->getArchiveFolder();
    }

    function canDeleteEmails() {
         return $this->account->canDeleteEmails();
    }


    function getTicketsApi() {
        // We're forcing CLI interface - this is absolutely necessary since
        // Email Fetching is considered a CLI operation regardless of how
        // it's triggered (cron job / task or autocron)

        // Please note that PHP_SAPI cannot be trusted for installations
        // using php-fpm or php-cgi binaries for php CLI executable.

        if (!isset($this->api))
            $this->api = new \TicketApiController('cli');

        return $this->api;
    }

    function noop() {
        return ($this->mbox && $this->mbox->noop());
    }

    function processMessage(int $i, array $defaults = []) {
        try {
            // Please note that the returned object could be anything from
            // ticket, task to thread entry or a boolean.
            // Don't let TicketApi call fool you!
            return $this->getTicketsApi()->processEmail(
                    $this->mbox->getRawEmail($i), $defaults);
        } catch (\TicketDenied $ex) {
            // If a ticket is denied we're going to report it as processed
            // so it can be moved out of the Fetch Folder or Deleted based
            // on the MailBox settings.
            return true;
        } catch (\EmailParseError $ex) {
            // Upstream we try to create a ticket on email parse error - if
            // it fails then that means we have invalid headers.
            // For Debug purposes log the parse error + headers as a warning
            $this->logWarning(sprintf("%s\n\n%s",
                        $ex->getMessage(),
                        $this->mbox->getRawHeader($i)));

        return ($create && $this->createMailbox($folder));
    }


    function decode($text, $encoding) {

        switch($encoding) {
            case 1:
            $text=imap_8bit($text);
            break;
            case 2:
            $text=imap_binary($text);
            break;
            case 3:
            if (strlen($text) > (1 << 20)) {
                try {
                    if (!($temp = tempnam(sys_get_temp_dir(), 'attachments'))
                        || !($f = fopen($temp, 'w'))
                        ) {
                            throw new Exception();
                    }
                    $s_filter = stream_filter_append($f, 'convert.base64-decode',STREAM_FILTER_WRITE);
                    if (!fwrite($f, $text))
                        throw new Exception();
                    stream_filter_remove($s_filter);
                    fclose($f);
                    if (!($f = fopen($temp, 'r')) || !($text = fread($f, filesize($temp))))
                        throw new Exception();
                    fclose($f);
                    unlink($temp);
                    break;
                }
                catch (Exception $e) {
                    // Noop. Fall through to imap_base64 method below
                    @fclose($f);
                    @unlink($temp);
                }
            }
            // imap_base64 implies strict mode. If it refuses to decode the
            // data, then fallback to base64_decode in non-strict mode
            $text = (($conv=imap_base64($text))) ? $conv : base64_decode($text);
            break;
            case 4:
            $text=imap_qprint($text);
            break;
        }
        return $text;
    }

    //Convert text to desired encoding..defaults to utf8
    function mime_encode($text, $charset=null, $encoding='utf-8') { //Thank in part to afterburner
        return Charset::transcode($text, $charset, $encoding);
    }

    function mailbox_encode($mailbox) {
        if (!$mailbox)
            return null;
        // Properly encode the mailbox to UTF-7, according to rfc2060,
        // section 5.1.3
        elseif (function_exists('mb_convert_encoding'))
            return mb_convert_encoding($mailbox, 'UTF7-IMAP', 'utf-8');
        else
            // XXX: This function has some issues on some versions of PHP
            return imap_utf7_encode($mailbox);
    }

    /**
     * Mime header value decoder. Usually unicode characters are encoded
     * according to RFC-2047. This function will decode RFC-2047 encoded
     * header values as well as detect headers which are not encoded.
     *
     * Caveats:
     * If headers contain non-ascii characters the result of this function
     * is completely undefined. If osTicket is corrupting your email
     * headers, your mail software is not encoding the header text
     * correctly.
     *
     * Returns:
     * Header value, transocded to UTF-8
     */
    function mime_decode($text, $encoding='utf-8') {
        // Handle poorly or completely un-encoded header values (
        if (function_exists('mb_detect_encoding'))
            if (($src_enc = mb_detect_encoding($text))
                    && (strcasecmp($src_enc, 'ASCII') !== 0))
                return Charset::transcode($text, $src_enc, $encoding);

        // Handle ASCII text and RFC-2047 encoding
        $str = '';
        $parts = imap_mime_header_decode($text);
        foreach ($parts as $part)
            $str.= $this->mime_encode($part->text, $part->charset, $encoding);

        return $str?$str:imap_utf8($text);
    }

    function getLastError() {
        return imap_last_error();
    }

    function getMimeType($struct) {
        $mimeType = array('TEXT', 'MULTIPART', 'MESSAGE', 'APPLICATION', 'AUDIO', 'IMAGE', 'VIDEO', 'OTHER');
        if(!$struct || !$struct->subtype)
            return 'TEXT/PLAIN';

        return $mimeType[(int) $struct->type].'/'.$struct->subtype;
    }

    function getHeaderInfo($mid) {

        if(!($headerinfo=imap_headerinfo($this->mbox, $mid)) || !$headerinfo->from)
            return null;

        $raw_header = $this->getHeader($mid);
        $info = array(
            'raw_header' => &$raw_header,
            'headers' => $headerinfo,
            'decoder' => $this,
            'type' => $this->getMimeType($headerinfo),
        );
        Signal::send('mail.decoded', $this, $info);

        // Respect "reply-to" address if present
	if (isset($headerinfo->reply_to)){
          $sender=$headerinfo->reply_to[0];
        } else {
          $sender=$headerinfo->from[0];
        }

        //Just what we need...
        $header=array('name'  => $this->mime_decode(@$sender->personal),
                      'email'  => trim(strtolower($sender->mailbox).'@'.$sender->host),
                      'subject'=> $this->mime_decode(@$headerinfo->subject),
                      'mid'    => trim(@$headerinfo->message_id),
                      'header' => $raw_header,
                      'in-reply-to' => $headerinfo->in_reply_to,
                      'references' => $headerinfo->references,
                      );

        if ($replyto = $headerinfo->reply_to) {
            $header['reply-to'] = $replyto[0]->mailbox.'@'.$replyto[0]->host;
            $header['reply-to-name'] = $replyto[0]->personal;
        }

        // Put together a list of recipients
        $tolist = array();
        if($headerinfo->to)
            $tolist['to'] = $headerinfo->to;
        if($headerinfo->cc)
            $tolist['cc'] = $headerinfo->cc;

        //Add delivered-to address to list.
        if (stripos($header['header'], 'delivered-to:') !==false
                && ($dt = Mail_Parse::findHeaderEntry($header['header'],
                     'delivered-to', true))) {
            if (($delivered_to = Mail_Parse::parseAddressList($dt)))
                $tolist['delivered-to'] = $delivered_to;
        }

        $header['system_emails'] = array();
        $header['recipients'] = array();
        $header['thread_entry_recipients'] = array();
        foreach($tolist as $source => $list) {
            foreach($list as $addr) {
                if (!($emailId=Email::getIdByEmail(strtolower($addr->mailbox).'@'.$addr->host))) {
                    //Skip virtual Delivered-To addresses
                    if ($source == 'delivered-to') continue;

                    $name = $this->mime_decode(@$addr->personal);
                    $email = strtolower($addr->mailbox).'@'.$addr->host;
                    $header['recipients'][] = array(
                            'source' => sprintf(_S("Email (%s)"),$source),
                            'name' => $name,
                            'email' => $email);

                    $header['thread_entry_recipients'][$source][] = sprintf('%s <%s>', $name, $email);
                } elseif ($emailId) {
                    $header['system_emails'][] = $emailId;
                    $system_email = Email::lookup($emailId);
                    $header['thread_entry_recipients']['to'][] = (string) $system_email;
                    if (!$header['emailId'])
                        $header['emailId'] = $emailId;
                }
            }
        }

        if (isset($header['thread_entry_recipients']['to']))
            $header['thread_entry_recipients']['to'] = array_unique($header['thread_entry_recipients']['to']);

        //See if any of the recipients is a delivered to address
        if ($tolist['delivered-to']) {
            foreach ($tolist['delivered-to'] as $addr) {
                foreach ($header['recipients'] as $i => $r) {
                    if (strcasecmp($r['email'], $addr->mailbox.'@'.$addr->host) === 0)
                        $header['recipients'][$i]['source'] = 'delivered-to';
                }
            }
        }

        //BCCed?
        if ($bcc = $headerinfo->bcc) {
            foreach ($bcc as $addr) {
                if (!($emailId=Email::getIdByEmail($addr->mailbox.'@'.$addr->host)))
                    continue;
                $header['system_emails'][] = $emailId;
                if (!$header['emailId'])
                    $header['emailId'] = $emailId;
            }
        }

        $header['system_emails'] = array_unique($header['system_emails']);

        // Ensure we have a message-id. If unable to read it out of the
        // email, use the hash of the entire email headers
        if (!$header['mid'] && $header['header']) {
            $header['mid'] = Mail_Parse::findHeaderEntry($header['header'],
                    'message-id');

            if (is_array($header['mid']))
                $header['mid'] = array_pop(array_filter($header['mid']));
            if (!$header['mid'])
                $header['mid'] = '<' . md5($header['header']) . '@local>';
        }

        return $header;
    }

    function fetchBody($mid, $index, $encoding) {
        $body = imap_fetchbody($this->mbox, $mid, $index);
        if ($body && $encoding)
            $body = $this->decode($body, $encoding);

        return $body;
    }

    //search for specific mime type parts....encoding is the desired encoding.
    function getPart($mid, $mimeType, $encoding=false, $struct=null,
        $partNumber=false, $recurse=-1, $recurseIntoRfc822=false) {

        if(!$struct && $mid)
            $struct=@imap_fetchstructure($this->mbox, $mid);

        //Match the mime type.
        if($struct
                && strcasecmp($mimeType, $this->getMimeType($struct))==0
                && (!$struct->ifdparameters
                    || !$this->findFilename($struct->dparameters))) {

            $partNumber=$partNumber?$partNumber:1;
            if(!($text=imap_fetchbody($this->mbox, $mid, $partNumber))
                    && $partNumber == 1
                    && $struct->disposition == 'inline')
                $text=imap_body($this->mbox, $mid);

            if ($text) {
                if($struct->encoding==3 or $struct->encoding==4) //base64 and qp decode.
                    $text=$this->decode($text, $struct->encoding);

                $charset=null;
                if ($encoding) { //Convert text to desired mime encoding...
                    if ($struct->ifparameters && $struct->parameters) {
                        foreach ($struct->parameters as $p) {
                            if (!strcasecmp($p->attribute, 'charset')) {
                                $charset = trim($p->value);
                                break;
                            }
                        }
                    }
                    $text = $this->mime_encode($text, $charset, $encoding);
                }
                return $text;
            }
        }

        if ($this->tnef && !strcasecmp($mimeType, 'text/html')
                && ($content = $this->tnef->getBody('text/html', $encoding)))
            return $content;

        // Do recursive search
        $text = '';
        $ctype = $this->getMimeType($struct);
        if ($struct && $struct->parts && $recurse
            // Do not recurse into email (rfc822) attachments unless requested
            && (strtolower($ctype) !== 'message/rfc822' || $recurseIntoRfc822)
        ) {
            foreach ($struct->parts as $i=>$substruct) {
                if ($partNumber)
                    $prefix = $partNumber . '.';
                if ($result = $this->getPart($mid, $mimeType, $encoding,
                    $substruct, $prefix.($i+1), $recurse-1, $recurseIntoRfc822)
                ) {
                    $text .= $result;
                }
            }
        }

        return $text;
    }

    /**
     * Searches the attribute list for a possible filename attribute. If
     * found, the attribute value is returned. If the attribute uses rfc5987
     * to encode the attribute value, the value is returned properly decoded
     * if possible
     *
     * Attribute Search Preference:
     *   filename
     *   filename*
     *   name
     *   name*
     */
    function findFilename($attributes) {
        foreach (array('filename', 'name') as $pref) {
            foreach ($attributes as $a) {
                if (strtolower($a->attribute) == $pref)
                    return $a->value;
                // Allow the RFC5987 specification of the filename
                elseif (strtolower($a->attribute) == $pref.'*')
                    return Format::decodeRfc5987($a->value);
            }
        }
        return false;
    }

    function processEmails() {
        // We need a connection
        if (!$this->mbox)
            return false;

        // Get basic fetch settings
        $archiveFolder = $this->getArchiveFolder();
        $deleteFetched =  $this->canDeleteEmails();
        $max = $this->getMaxFetch() ?: 30; // default to 30 if not set

        // Get message count in the Fetch Folder
        if (!($messageCount = $this->mbox->countMessages()))
            return 0;

        // If the number of emails in the folder are more than Max Fetch
        // then process the latest $max emails - this is necessary when
        // fetched emails are not getting archived or deleted, which might
        // lead to fetcher being stuck 4ever processing old emails already
        // fetched
        if ($messageCount > $max) {
            // Latest $max messages
            $messages = range($messageCount-$max, $messageCount);
        } else {
            // Create a range of message sequence numbers (msgno) to fetch
            // starting from the oldest taking max fetch into account
            $messages = range(1, min($max, $messageCount));
        }

        $defaults = [
            'emailId' => $this->getEmailId()
        ];
        $msgs = $errors = 0;
        // TODO: Use message UIDs instead of ids
        foreach ($messages as $i) {
            try {
                // Okay, let's try to create a ticket
                if (($result=$this->processMessage($i, $defaults))) {
                    // Mark the message as "Seen" (IMAP only)
                    $this->mbox->markAsSeen($i);
                    // Attempt to move the message if archive folder is set or
                    if ($archiveFolder)
                        $this->mbox->moveMessage($i, $archiveFolder);
                    elseif ($deleteFetched)  // else delete if deletion is desired
                        $this->mbox->removeMessage($i);
                    $msgs++;
                    $errors = 0; // We are only interested in consecutive errors.
                } else {
                    $errors++;
                }
            } catch (\Throwable $t) {
                // If we have result then exception happened after email
                // processing and shouldn't count as an error
                if (!$result)
                    $errors++;
                // log the exception as a debug message
                $this->logDebug($t->getMessage());
            }
        }

        // Expunge the mailbox
        $this->mbox->expunge();

        // Warn on excessive errors - when errors are more than email
        // processed successfully.
        if ($errors > $msgs) {
            $warn = sprintf("%s\n\n%s [%d/%d - %d/%d]",
                    // Mailbox Info
                    sprintf(_S('Excessive errors processing emails for %1$s (%2$s).'),
                        $this->mbox->getHostInfo(), $this->getEmail()),
                    // Fetch Folder
                    sprintf('%s (%s)',
                        _S('Please manually check the Fetch Folder'),
                        $this->getFetchFolder()),
                    // Counts - sort of cryptic but useful once we document
                    // what it means
                    $messageCount, $max, $msgs, $errors);
            $this->logWarning($warn);
        }
        return $msgs;
    }

    private function logDebug($msg) {
        $this->log($msg, LOG_DEBUG);
    }

    private function logWarning($msg) {
        $this->log($msg, LOG_WARN);
    }

    private function log($msg, $level = LOG_WARN) {
        global $ost;
        $subj = _S('Mail Fetcher');
        switch ($level) {
            case LOG_WARN:
                $ost->logWarning($subj, $msg);
                break;
            case  LOG_DEBUG:
            default:
                $ost->logDebug($subj, $msg);
        }
    }

    /*
       MailFetcher::run()

       Static function called to initiate email polling
     */
    static function run() {
        global $ost;

        if(!$ost->getConfig()->isEmailPollingEnabled())
            return;

        //Hardcoded error control...
        $MAXERRORS = 5; //Max errors before we start delayed fetch attempts
        $TIMEOUT = 10; //Timeout in minutes after max errors is reached.
        $now = \SqlFunction::NOW();
        // Num errors + last error
        $interval = new \SqlInterval('MINUTE', $TIMEOUT);
        $errors_Q = \Q::any([
                'num_errors__lte' => $MAXERRORS,
                new \Q(['last_error__lte' => $now->minus($interval)])
        ]);
        // Last fetch + frequency
        $interval = new \SqlInterval('MINUTE', \SqlExpression::plus(new
                     \SqlCode('fetchfreq'), 0));
        $fetch_Q = \Q::any([
                'last_activity__isnull' => true,
                new \Q(['last_activity__lte' => $now->minus($interval)])
        ]);

        $mailboxes = \MailBoxAccount::objects()
            ->filter(['active' => 1, $errors_Q, $fetch_Q])
            ->order_by('last_activity');

        //Get max execution time so we can figure out how long we can fetch
        // take fetching emails.
        if (!($max_time = ini_get('max_execution_time')))
            $max_time = 300;

        //Start time
        $start_time = \Misc::micro_time();
        foreach ($mailboxes as $mailbox) {
            // Check if the mailbox is active 4realz by getting credentials
            if (!$mailbox->isActive())
                continue;

            // Break if we're 80% into max execution time
            if ((\Misc::micro_time()-$start_time) > ($max_time*0.80))
                break;

            // Try fetching emails
            try {
                $mailbox->fetchEmails();
            } catch (\Throwable $t) {
                if ($mailbox->getNumErrors() >= $MAXERRORS && $ost) {
                    //We've reached the MAX consecutive errors...will attempt logins at delayed intervals
                    // XXX: Translate me
                    $msg = sprintf("\n %s:\n",
                            _S('osTicket is having trouble fetching emails from the following mail account')).
                        "\n"._S('Email').": ".$mailbox->getEmail()->getAddress().
                        "\n"._S('Host Info').": ".$mailbox->getHostInfo().
                        "\n"._S('Error').": ".$t->getMessage().
                        "\n\n ".sprintf(_S('%1$d consecutive errors. Maximum of %2$d allowed'),
                                $mailbox->getNumErrors(), $MAXERRORS).
                        "\n\n ".sprintf(_S('This could be connection issues related to the mail server. Next delayed login attempt in aprox. %d minutes'), $TIMEOUT);
                    $ost->alertAdmin(_S('Mail Fetch Failure Alert'), $msg, true);
                }
            }
        } //end foreach.
    }
}
?>
