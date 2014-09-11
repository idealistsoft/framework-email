<?php

namespace app\email\services;

use infuse\Util;

use App;

if( !defined( 'EMAIL_QUEUE_NAME' ) )
    define( 'EMAIL_QUEUE_NAME', 'emails' );

class EmailService
{
    private $app;
    private $mandrill;
    private $smtp;
    private $nop;
    private $fromEmail;
    private $fromName;

    public function __construct(array $settings, App $app)
    {
        $this->app = $app;

        $this->fromEmail = Util::array_value( $settings, 'from_email' );
        $this->fromName = Util::array_value( $settings, 'from_name' );

        if ($settings[ 'type' ] == 'smtp') {
            $transport = \Swift_SmtpTransport::newInstance( $settings[ 'host' ], $settings[ 'port' ] )
              ->setUsername( $settings[ 'username' ] )
              ->setPassword( $settings[ 'password' ] );

            $this->smtp = \Swift_Mailer::newInstance( $transport );
        } elseif ($settings[ 'type' ] == 'mandrill') {
            $this->mandrill = new \Mandrill( $settings[ 'key' ] );
        } elseif ($settings[ 'type' ] == 'nop') {
            $this->nop = true;
        }
    }

    /**
	 * Queues an email
	 *
	 * @param string $template name of template
	 * @param array $message
	 *
	 * @return boolean
	 */
    public function queueEmail($template, array $message)
    {
        return $this->app[ 'queue' ]->enqueue(
            EMAIL_QUEUE_NAME,
            [
                't' => $template,
                'm' => $message ],
            [
                'timeout' => 60,
                'expires_in' => 2592000 ] );
    }

    /**
	 * Sends an email
	 *
	 * @param string $template name of template
	 * @param array $message
	 *
	 * @return array
	 */
    public function sendEmail($template, array $message)
    {
        // render the body from the template
        if ($template) {
            if( !isset( $message[ 'html' ] ) )
                $message[ 'html' ] = $this->app[ 'view_engine' ]->render( 'emails/' . $template, $message );
            if( !isset( $message[ 'text' ] ) )
                $message[ 'text' ] = $this->app[ 'view_engine' ]->render( 'emails/text/' . $template, $message );
        }

        // figure out who email will be from
        if( !isset( $message[ 'from_email' ] ) )
            $message[ 'from_email' ] = $this->fromEmail;

        if( !isset( $message[ 'from_name' ] ) )
            $message[ 'from_name' ] = $this->fromName;

        // figure out recipients
        $to = [];
        foreach( (array) $message[ 'to' ] as $item )
            $to[ $item[ 'email' ] ] = $item[ 'name' ];

        try {
            /* Mandrill API */
            if ($this->mandrill) {
                return $this->mandrill->messages->send( $message );
            }
            /* Swift Mailer SMTP */
            elseif ($this->smtp) {
                $sMessage = \Swift_Message::newInstance( $message[ 'subject' ] )
                  ->setFrom( [ $message[ 'from_email' ] => $message[ 'from_name' ] ] )
                  ->setTo( $to )
                  ->setBody( $message[ 'html' ], 'text/html' );

                if( isset( $message[ 'text' ] ) )
                    $sMessage->addPart( $message[ 'text' ], 'text/plain' );

                if ( isset( $message[ 'headers' ] ) && is_array( $message[ 'headers' ] ) ) {
                    $headers = $sMessage->getHeaders();

                    foreach( $message[ 'headers' ] as $k => $v )
                        $headers->addTextHeader( $k, $v );
                }

                $sent = $this->smtp->send( $sMessage );

                return array_fill( 0, count( $to ), [
                    'status' => ($sent) ? 'sent' : 'rejected' ] );
            }
            /* NOP */
            elseif ($this->nop) {
                return array_fill( 0, count( $to ), array_replace( $message, [
                    'to_alt' => $to,
                    'status' => 'sent' ] ) );
            }
        } catch ( \Exception $e ) {
            $errorStack = $this->app[ 'errors' ];
            $errorStack->push( [ 'error' => 'email_send_failure' ] );

            $this->app[ 'logger' ]->addError( $e );

            return array_fill( 0, count( $to ), [ 'status' => 'invalid' ] );
        }
    }
}