<?php
// backend/app/config/Mail.php

// Load PHPMailer classes based on your specific folder structure
// 1. Check if the class is already in memory BEFORE requiring the files
if (!class_exists('PHPMailer\PHPMailer\Exception')) {
    require_once __DIR__ . '/../../Vendor/PHPMailer/src/Exception.php';
    require_once __DIR__ . '/../../Vendor/PHPMailer/src/PHPMailer.php';
    require_once __DIR__ . '/../../Vendor/PHPMailer/src/SMTP.php';
}

// 2. These stay exactly where they are (outside the if statement)
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class Mail {
    // Added an optional 5th parameter ($altBody) to prevent the "Too few arguments" error
    public static function send($toEmail, $toName, $subject, $body, $altBody = null) {
        $mail = new PHPMailer(true);

        try {
            // 1. SMTP Server Settings
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';                     
            $mail->SMTPAuth   = true;                                 
            
            // Your Live Gmail Credentials
            // $mail->Username   = 'usevendorapos@gmail.com'; 
            // $mail->Password   = 'tmmp mthf xibl cein';  
            
            // NOTE: When launching to production, you can switch back to environment variables:
            $mail->Username   = $_ENV['SMTP_USER']; 
            $mail->Password   = $_ENV['SMTP_PASS'];

            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;       
            $mail->Port       = 587;                                  

            // 2. Recipients
            $mail->setFrom('noreply@vendora.com', 'Vendora SaaS');
            $mail->addAddress($toEmail, $toName);

            // 3. Email Content
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $body;
            
            // Fallback for non-HTML email clients
            $mail->AltBody = $altBody !== null ? $altBody : strip_tags($body); 

            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log("Message could not be sent. Mailer Error: {$mail->ErrorInfo}");
            return false;
        }
    }
}