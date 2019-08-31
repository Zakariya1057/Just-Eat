<?php

    require_once  __DIR__.'/vendor/autoload.php';

    use PHPMailer\PHPMailer\PHPMailer;

    class email {

        public function send($message){

            $mail = new PHPMailer(true);

            try {
                $date = date('Y-m-d');
                //Server settings
                $mail->SMTPDebug = 0;                                       // Enable verbose debug output
                $mail->isSMTP();                                            // Set mailer to use SMTP
                $mail->Host       = 'secure251.inmotionhosting.com';  // Specify main and backup SMTP servers
                $mail->SMTPAuth   = true;                                   // Enable SMTP authentication
                $mail->Username   = 'zack@zedbite.com';                     // SMTP username
                $mail->Password   = 'BW{.iwL0@gHk';                               // SMTP password
                $mail->SMTPSecure = 'tls';                                  // Enable TLS encryption, `ssl` also accepted
                $mail->Port       = 587;                                    // TCP port to connect to
        
                //Recipients
                $mail->setFrom('zack@zedbite.com', 'Zedbite');
                $mail->addAddress('zakaria2011@live.no', 'User');  
        
                // Content
                $mail->isHTML(true);   
                $mail->addAttachment( __DIR__."/logs/$date/error.log");                                 // Set email format to HTML

                $mail->Subject = 'Scrape Error';
                $mail->Body    = $message;
        
                $mail->send();
            
        } catch (Exception $e) {
            echo "Message could not be sent. Mailer Error";
        }

    }
}


?>