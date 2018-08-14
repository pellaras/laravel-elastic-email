<?php

namespace ArtisanMY\LaravelElasticEmail;

use GuzzleHttp\ClientInterface;
use Illuminate\Mail\Transport\Transport;
use Swift_Mime_SimpleMessage;
use Swift_Attachment;
use Swift_Image;

class ElasticTransport extends Transport
{
    
    /**
     * Guzzle client instance.
     *
     * @var \GuzzleHttp\ClientInterface
     */
    protected $client;

    /**
     * The Elastic Email API key.
     *
     * @var string
     */
    protected $key;

    /**
     * The Elastic Email username.
     *
     * @var string
     */
    protected $account;

    /**
     * THe Elastic Email API end-point.
     *
     * @var string
     */
    protected $url = 'https://api.elasticemail.com/v2/email/send';

    const MAXIMUM_FILE_SIZE = 10485760;

    /**
     * Create a new Elastic Email transport instance.
     *
     * @param  \GuzzleHttp\ClientInterface  $client
     * @param  string  $key
     * @param  string  $username
     *
     * @return void
     */
    public function __construct(ClientInterface $client, $key, $account)
    {
        $this->client = $client;
        $this->key = $key;
        $this->account = $account;
    }

    /**
     * {@inheritdoc}
     */
    public function send(Swift_Mime_SimpleMessage $message, &$failedRecipients = null)
    {
        $this->beforeSendPerformed($message);
       
        $data = [
            'api_key' => $this->key,
            'account' => $this->account,
            'msgTo' => $this->getEmailAddresses($message),
            'msgCC' => $this->getEmailAddresses($message, 'getCc'),
            'msgBcc' => $this->getEmailAddresses($message, 'getBcc'),
            'msgFrom' => $this->getFromAddress($message)['email'],
            'msgFromName' => $this->getFromAddress($message)['name'],
            'from' => $this->getFromAddress($message)['email'],
            'fromName' => $this->getFromAddress($message)['name'],
            'subject' => $message->getSubject(),
            'body_html' => $message->getBody(),
            'body_text' => $this->getText($message),
        ];

        if ($this->getReplyToAddress($message)) {
            $data['replyTo'] = $this->getReplyToAddress($message)['email'];
            $data['replyToName'] = $this->getReplyToAddress($message)['name'];
        }

        $data = $this->getParameters($message, $data);

        $attachments = $this->getAttachments($message);

        if (count($attachments)) {
            $_data = [];

            foreach ($data AS $key => $value) {
                $_data[] = [
                    'name' => $key,
                    'contents' => $value,
                ];
            }

            foreach ($attachments AS $key => $file) {
                $_data[] = [
                    'name' => 'file_' . $key,
                    'contents' => $file['contents'],
                    'filename' => $file['filename'],
                ];
            }
            
            $data = $_data;
        }

        $form_type = count($attachments) ? 'multipart' : 'form_params';

        $result = $this->client->post($this->url, [
            $form_type => $data,
        ]);
        
        return $result;
    }

    protected function getParameters(Swift_Mime_SimpleMessage $message, $data)
    {
        $available_parameters = [
            'channel' => null,
            'isTransactional' => 1,
        ];

        $headers = $message->getHeaders();

        foreach ($available_parameters AS $parameter_key => $parameter_default_value) {
            if ($headers->has($parameter_key)) {
                $data[$parameter_key] = $headers->get($parameter_key)->getFieldBody();
            }
            elseif ($parameter_default_value !== null) {
                $data[$parameter_key] = $parameter_default_value;
            }
        }

        return $data;
    }

    private function getAttachments(Swift_Mime_SimpleMessage $message)
    {
        $attachments = [];

        foreach ($message->getChildren() as $attachment) {
            if ((!$attachment instanceof Swift_Attachment && !$attachment instanceof Swift_Image)
                || !strlen($attachment->getBody()) > self::MAXIMUM_FILE_SIZE
            ) {
                continue;
            }
            $attachments[] = [
                'contents' => $attachment->getBody(),
                'filename' => $attachment->getFilename(),
            ];
        }

        return $attachments;
    }

    /**
     * Get the plain text part.
     *
     * @param  \Swift_Mime_Message $message
     * @return text|null
     */
    protected function getText(Swift_Mime_SimpleMessage $message)
    {
        $text = null;
        
        foreach($message->getChildren() as $child)
        {
            if($child->getContentType() == 'text/plain')
            {
                $text = $child->getBody();
            }
        }
        
        return $text;
    }
    
    /**
     * @param \Swift_Mime_Message $message
     *
     * @return array
     */
    protected function getFromAddress(Swift_Mime_SimpleMessage $message)
    {
        return [
            'email' => array_keys($message->getFrom())[0],
            'name' => array_values($message->getFrom())[0],
        ];
    }
    
    protected function getReplyToAddress(Swift_Mime_SimpleMessage $message)
    {
        if (! $message->getReplyTo()) {
            return false;
        }

        return [
            'email' => array_keys($message->getReplyTo())[0],
            'name' => array_values($message->getReplyTo())[0],
        ];
    }
    
    protected function getEmailAddresses(Swift_Mime_SimpleMessage $message, $method = 'getTo')
    {
        $data = call_user_func([$message, $method]);
        
        if(is_array($data))
        {
            return implode(',', array_keys($data));
        }
        return '';
    }
}
