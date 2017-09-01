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


abstract class AbstractTransport implements TransportInterface
{
    /** @var string[] Boundaries */
    private $boundaries = [];

    /**
     * @inheritdoc
     */
    public function massSend(\Berlioz\Mailer\Mail $mail, array $addresses, callable $callback = null)
    {
        $i = 0;
        /** @var \Berlioz\Mailer\Address $address */
        foreach ($addresses as $address) {
            // Reset and set new address
            $mail->resetRecipients();
            $mail->setTo(is_array($address) ? $address : [$address]);

            // Send
            $this->send($mail);

            if (is_callable($callback)) {
                $callback($address, $i);
            }

            $i++;
        }

        // And... reset ;)
        $mail->resetRecipients();
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
     * @param \Berlioz\Mailer\Mail $mail    Mail
     * @param array                $exclude Headers to exclude
     *
     * @return string[]
     */
    protected function getHeaders(\Berlioz\Mailer\Mail $mail, array $exclude = []): array
    {
        array_walk($exclude, 'mb_strtolower');
        $contents = [];
        $headers = $mail->getFinalHeaders();
        $headers['MIME-Version'] = ['1.0'];

        foreach ($headers as $name => $values) {
            if (!in_array(mb_strtolower($name), $exclude)) {
                foreach ((array) $values as $value) {
                    $contents[] = sprintf('%s: %s',
                                          $name,
                                          mb_encode_mimeheader($value,
                                                               mb_internal_encoding(),
                                                               'B',
                                                               $this->getLineFeed()));
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
        // Attachments
        $attachments = $mail->getAttachments();
        $htmlAttachments =
            array_filter(
                $attachments,
                function ($attachment) {
                    /** @var \Berlioz\Mailer\Attachment $attachment */
                    return $attachment->hasId();
                });
        $attachments =
            array_filter(
                $attachments,
                function ($attachment) {
                    /** @var \Berlioz\Mailer\Attachment $attachment */
                    return !$attachment->hasId();
                });

        // Contents
        {
            $contents = [];
            // If has $attachments
            if (count($attachments) > 0) {
                $contents[] = sprintf('Content-Type: multipart/mixed; boundary="%s"', $this->getBoundary('mixed'));
                $contents[] = '';
                $contents[] = 'This is a multi-part message in MIME format.';
                $contents[] = '';
                $contents[] = sprintf('--%s', $this->getBoundary('mixed'));
                $contents[] = '';
            }
            // If has text and html
            if ($mail->hasText() && $mail->hasHtml()) {
                $contents[] = sprintf('Content-Type: multipart/alternative; boundary="%s"', $this->getBoundary('alternative'));
                $contents[] = '';
                $contents[] = 'This is a multi-part message in MIME format.';
                $contents[] = '';
                $contents[] = sprintf('--%s', $this->getBoundary('alternative'));
                $contents[] = '';
            }

            // Text
            if ($mail->hasText()) {
                $contents[] = sprintf('Content-Type: text/plain; charset="%s"; format=flowed; delsp=yes',
                                      mb_detect_encoding($mail->getText()));
                $contents[] = 'Content-Transfer-Encoding: base64';
                $contents[] = '';
                $contents = array_merge($contents,
                                        str_split(base64_encode($mail->getText()), 77));
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
                    $contents[] = '';
                }

                $contents[] = sprintf('Content-Type: text/html; charset="%s"; format=flowed; delsp=yes',
                                      mb_detect_encoding($mail->getHtml()));
                $contents[] = 'Content-Transfer-Encoding: quoted-printable';
                $contents[] = '';
                $contents = array_merge($contents, str_split(quoted_printable_encode(str_replace(["\r", "\n"], '', $mail->getHtml())), 77));
                $contents[] = '';

                if (count($htmlAttachments) > 0) {
                    /** @var \Berlioz\Mailer\Attachment $attachment */
                    foreach ($htmlAttachments as $attachment) {
                        $contents[] = sprintf('--%s', $this->getBoundary('related'));
                        $contents[] = sprintf('Content-Type: %s; name="%s"',
                                              $attachment->getType(),
                                              $attachment->getName());
                        $contents[] = 'Content-Transfer-Encoding: base64';
                        $contents[] = sprintf('Content-ID: <%s>', $attachment->getId());
                        $contents[] = '';
                        $contents = array_merge($contents, str_split(base64_encode(file_get_contents($attachment->getContents()))), 77);
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
                    $contents[] = sprintf('Content-Type: %s; name="%s"',
                                          $attachment->getType(),
                                          $attachment->getName());
                    $contents[] = 'Content-Transfer-Encoding: base64';
                    $contents[] = 'Content-Disposition: attachment;';
                    $contents[] = sprintf('    filename="%s"', $attachment->getName());
                    $contents[] = '';
                    $contents = array_merge($contents, str_split(base64_encode(file_get_contents($attachment->getContents()))), 77);
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
     * @param string $type   Boundary type (mixed, related or alternative)
     * @param int    $length Length of boundary
     *
     * @return string
     */
    private function getBoundary(string $type, $length = 24): string
    {
        if (empty($this->boundaries[$type])) {
            $source = '0123456789';

            $length = abs(intval($length));
            $n = strlen($source);
            $this->boundaries[$type] = '';

            for ($i = 0; $i < $length; $i++) {
                $this->boundaries[$type] .= $source{mt_rand(1, $n) - 1};
            }
        }

        return $this->boundaries[$type];
    }
}