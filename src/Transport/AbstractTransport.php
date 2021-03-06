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

declare(strict_types=1);

namespace Berlioz\Mailer\Transport;

abstract class AbstractTransport implements TransportInterface
{
    /** @var string[] Boundaries */
    private $boundaries = [];

    /**
     * @inheritdoc
     * @return array
     */
    public function massSend(\Berlioz\Mailer\Mail $mail, array $addresses, callable $callback = null)
    {
        $result = [];

        $i = 0;
        /** @var \Berlioz\Mailer\Address $address */
        foreach ($addresses as $address) {
            // Reset and set new address
            $mail->resetRecipients();
            $mail->setTo(is_array($address) ? $address : [$address]);

            // Send
            $result[] = $this->send($mail);

            if (is_callable($callback)) {
                $callback($address, $i);
            }

            $i++;
        }

        // And... reset ;)
        $mail->resetRecipients();

        return $result;
    }

    /**
     * @inheritdoc
     */
    public function getLineFeed(): string
    {
        return "\r\n";
    }

    /**
     * Get headers.
     *
     * Note: This method is used by transport, users use getHeaders().
     *
     * @param \Berlioz\Mailer\Mail $mail Mail
     * @param array $exclude Headers to exclude
     *
     * @return string[]
     */
    protected function getHeaders(\Berlioz\Mailer\Mail $mail, array $exclude = []): array
    {
        $exclude = array_map('mb_strtolower', $exclude);
        $contents = [];

        // Get headers
        $headers = $mail->getHeaders();
        $headers['MIME-Version'] = ['1.0'];
        if (!in_array('subject', $exclude)) {
            $headers['Subject'] = [mb_encode_mimeheader($mail->getSubject(), mb_detect_encoding($mail->getSubject()), 'Q')];
        }

        // Addresses
        {
            if (!in_array('from', $exclude)) {
                $contents[] = sprintf('%s: %s', 'From', (string)$mail->getFrom());
            }

            if (!in_array('to', $exclude)) {
                if (count($mail->getTo()) > 0) {
                    $contents[] = sprintf('%s: %s', 'To', implode(', ', $mail->getTo()));
                } else {
                    $contents[] = 'To: undisclosed-recipients:;';
                }
            }

            if (!in_array('cc', $exclude) && count($mail->getCc()) > 0) {
                $contents[] = sprintf('%s: %s', 'Cc', implode(', ', $mail->getCc()));
            }

            if (!in_array('bcc', $exclude) && count($mail->getBcc()) > 0) {
                $contents[] = sprintf('%s: %s', 'Bcc', implode(', ', $mail->getBcc()));
            }
        }

        foreach ($headers as $name => $values) {
            if (!in_array(mb_strtolower($name), $exclude)) {
                foreach ((array)$values as $value) {
                    $contents[] = sprintf('%s: %s', $name, trim($value));
                }
            }
        }

        return $contents;
    }

    /**
     * Get contents.
     *
     * @param \Berlioz\Mailer\Mail $mail Mail
     *
     * @return string[]
     */
    protected function getContents(\Berlioz\Mailer\Mail $mail): array
    {
        $contents = [];

        // Attachments
        $attachments = $mail->getAttachments();
        $htmlAttachments =
            array_filter(
                $attachments,
                function ($attachment) {
                    /** @var \Berlioz\Mailer\Attachment $attachment */
                    return $attachment->hasId();
                }
            );
        $attachments =
            array_filter(
                $attachments,
                function ($attachment) {
                    /** @var \Berlioz\Mailer\Attachment $attachment */
                    return !$attachment->hasId();
                }
            );

        // Contents
        {
            // If has $attachments
            if (count($attachments) > 0) {
                $contents[] = sprintf('Content-Type: multipart/mixed; boundary="%s"', $this->getBoundary('mixed'));
                $contents[] = '';
                $contents[] = 'This is a multi-part message in MIME format.';
                $contents[] = '';
                $contents[] = sprintf('--%s', $this->getBoundary('mixed'));
            }
            // If has text and html
            if ($mail->hasText() && $mail->hasHtml()) {
                $contents[] = sprintf('Content-Type: multipart/alternative; boundary="%s"', $this->getBoundary('alternative'));
                $contents[] = '';
                $contents[] = 'This is a multi-part message in MIME format.';
                $contents[] = '';
                $contents[] = sprintf('--%s', $this->getBoundary('alternative'));
            }

            // Text
            if ($mail->hasText()) {
                $contents[] = sprintf(
                    'Content-Type: text/plain; charset="%s"; format=flowed; delsp=yes',
                    mb_detect_encoding($mail->getText())
                );
                $contents[] = 'Content-Transfer-Encoding: base64';
                $contents[] = '';
                $contents = array_merge($contents, str_split(base64_encode($mail->getText()), 76));
                $contents[] = '';
            }

            // Html
            if ($mail->hasHtml()) {
                if ($mail->hasText()) {
                    $contents[] = '';
                    $contents[] = sprintf('--%s', $this->getBoundary('alternative'));
                }

                if (count($htmlAttachments) > 0) {
                    $contents[] = sprintf('Content-Type: multipart/related; boundary="%s"', $this->getBoundary('related'));
                    $contents[] = '';
                    $contents[] = 'This is a multi-part message in MIME format.';
                    $contents[] = '';
                    $contents[] = sprintf('--%s', $this->getBoundary('related'));
                }

                $contents[] = sprintf(
                    'Content-Type: text/html; charset="%s"; format=flowed; delsp=yes',
                    mb_detect_encoding($mail->getHtml())
                );
                $contents[] = 'Content-Transfer-Encoding: quoted-printable';
                $contents[] = '';
                $contents = array_merge($contents, explode("\r\n", quoted_printable_encode($mail->getHtml(true))));
                $contents[] = '';

                if (count($htmlAttachments) > 0) {
                    /** @var \Berlioz\Mailer\Attachment $attachment */
                    foreach ($htmlAttachments as $attachment) {
                        $contents[] = sprintf('--%s', $this->getBoundary('related'));
                        $contents[] = sprintf(
                            'Content-Type: %s; name="%s"',
                            $attachment->getType(),
                            $attachment->getName()
                        );
                        $contents[] = 'Content-Transfer-Encoding: base64';
                        $contents[] = 'Content-Disposition: inline';
                        $contents[] = sprintf('Content-ID: <%s>', $attachment->getId());
                        $contents[] = '';
                        $contents = array_merge($contents, str_split(base64_encode($attachment->getContents()), 76));
                        $contents[] = '';
                    }
                    $contents[] = sprintf('--%s--', $this->getBoundary('related'));
                }
            }

            // Attachments
            if (count($attachments) > 0) {
                /** @var \Berlioz\Mailer\Attachment $attachment */
                foreach ($attachments as $attachment) {
                    $contents[] = sprintf('--%s', $this->getBoundary('mixed'));
                    $contents[] = sprintf(
                        'Content-Type: %s; name="%s"',
                        $attachment->getType(),
                        $attachment->getName()
                    );
                    $contents[] = 'Content-Transfer-Encoding: base64';
                    $contents[] = 'Content-Disposition: attachment;';
                    $contents[] = sprintf('    filename="%s"', $attachment->getName());
                    $contents[] = '';
                    $contents = array_merge($contents, str_split(base64_encode($attachment->getContents()), 76));
                    $contents[] = '';
                }
                $contents[] = sprintf('--%s--', $this->getBoundary('mixed'));
            }
        }

        return $contents;
    }

    /**
     * Get boundary.
     *
     * @param string $type Boundary type (mixed, related or alternative)
     * @param string|null $prefix Prefix
     * @param int $length Length of boundary
     *
     * @return string
     */
    private function getBoundary(string $type, string $prefix = null, int $length = 12): string
    {
        if (empty($this->boundaries[$type])) {
            $source = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';

            $length = abs(intval($length));
            $n = strlen($source);
            $this->boundaries[$type] = '--' . ($prefix ? $prefix . '-' : '');

            for ($i = 0; $i < ($length - 2); $i++) {
                $this->boundaries[$type] .= $source[mt_rand(1, $n) - 1];
            }

            $this->boundaries[$type] = substr($this->boundaries[$type], 0, $length);
        }

        return $this->boundaries[$type];
    }
}