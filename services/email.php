<?php

    require_once  __DIR__.'/../vendor/autoload.php';
    require_once __DIR__.'/../config/config.php';

    use PHPMailer\PHPMailer\PHPMailer;


    function send_email($message){

            global $config,$logger;

            $email_config = $config->email->configuration;

            $mail = new PHPMailer(true);

            try {
                $date = date('d-m-Y');
                //Server settings
                $mail->SMTPDebug = 0;                                       // Enable verbose debug output
                $mail->isSMTP();                                            // Set mailer to use SMTP
                $mail->Host       = $email_config->host;                    // Specify main and backup SMTP servers
                $mail->SMTPAuth   = $email_config->smpt_auth;               // Enable SMTP authentication
                $mail->Username   = $email_config->username;                // SMTP username
                $mail->Password   = $email_config->password;                // SMTP password
                $mail->SMTPSecure = $email_config->smtp_sercure;    
                $mail->Port       = $email_config->port;                                    // TCP port to connect to
        
                //Recipients
                $mail->setFrom($config->email->from, 'Zedbite');
                $mail->addAddress($config->email->to, 'User');  
        
                // Content
                $mail->isHTML(true);   
                $mail->addAttachment( __DIR__."/../logs/$config->city/$date/debug.log");                                 // Set email format to HTML

                $mail->Subject = $config->email->subject;
                $mail->Body    = $message;
        
                $mail->send();

                $logger->debug('Error Email Successfully Sent To '.$config->email->to);
            
        } catch (Exception $e) {
            $logger->error("Message could not be sent. Mailer Error: ".$e->getMessage());
        }

    }

?>