<?php
/**
 * This file is part of Berlioz framework.
 *
 * @license   https://opensource.org/licenses/MIT MIT License
 * @copyright 2017 Ronan GIRON
 * @author    Ronan GIRON <https://github.com/ElGigi>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code, to the root.
 */

namespace Berlioz\Mailer\Transport;


class SparkPostSmtpApi extends Smtp
{
    /** @var array SparkPost options */
    private $sparkPostOptions = [];

    /**
     * SparkPostSmtpApi constructor.
     *
     * @param string|null $apiKey           API key for SparkPost SMTP API injection.
     * @param array       $sparkPostOptions SparkPost options (X-MSYS-API header)
     * @param array       $smtpOptions      SMTP options
     *
     * @link https://developers.sparkpost.com/api/smtp-api.html
     */
    public function __construct(string $apiKey = null,
                                array $sparkPostOptions = [],
                                array $smtpOptions = [])
    {
        parent::__construct($smtpOptions['host'] ?? 'smtp.sparkpostmail.com',
                            $smtpOptions['username'] ?? 'SMTP_Injection',
                            $apiKey,
                            $smtpOptions['port'] ?? 587,
                            $smtpOptions);

        $this->sparkPostOptions = $sparkPostOptions;
    }

    /**
     * @inheritdoc
     */
    public static function __set_state($an_array): array
    {
        return array_merge(parent::__set_state($an_array),
                           ['sparkPostOptions' => $an_array['sparkPostOptions']]);
    }

    /**
     * @inheritdoc
     */
    public function __debugInfo(): array
    {
        return array_merge(parent::__debugInfo(),
                           ['sparkPostOptions' => $this->sparkPostOptions]);
    }

    /**
     * @inheritdoc
     */
    protected function getHeaders(\Berlioz\Mailer\Mail $mail, array $exclude = []): array
    {
        $exclude = array_merge(['x-msys-api'], $exclude);
        $contents = parent::getHeaders($mail, $exclude);

        // X-MSYS-API header from Mail object
        $headerMsysApi = [];
        foreach ($mail->getHeader('X-MSYS-API') as $header) {
            $headerMsysApi = array_merge($headerMsysApi, json_decode($header, true) ?? []);
        }

        // Merge with SparkPost options given in constructor
        $headerMsysApi = array_merge($headerMsysApi, $this->sparkPostOptions);

        // Create header
        if (!empty($headerMsysApi)) {
            $contents[] = sprintf('X-MSYS-API: %s', json_encode($headerMsysApi, JSON_FORCE_OBJECT));
        }

        return $contents;
    }
}