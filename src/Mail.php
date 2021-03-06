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

namespace Berlioz\Mailer;

use Berlioz\Mailer\Exception\InvalidArgumentException;

class Mail
{
    const RESERVED_HEADERS = ['Subject', 'From', 'To', 'Cc', 'Bcc'];
    /** @var array Headers */
    private $headers;
    /** @var \Berlioz\Mailer\Address From */
    private $from;
    /** @var \Berlioz\Mailer\Address[] To */
    private $to;
    /** @var \Berlioz\Mailer\Address[] Cc */
    private $cc;
    /** @var \Berlioz\Mailer\Address[] Bcc */
    private $bcc;
    /** @var string Subject */
    private $subject;
    /** @var string Text body */
    private $text;
    /** @var string Html body */
    private $html;
    /** @var \Berlioz\Mailer\Attachment[] $attachments */
    private $attachments;

    /**
     * Get additional headers.
     *
     * @return array
     */
    public function getHeaders(): array
    {
        return $this->headers ?? [];
    }

    /**
     * Set additional headers.
     *
     * WARNING: headers values needs to be encoded before!
     *
     * @param array $headers
     *
     * @return static
     * @throws \Berlioz\Mailer\Exception\InvalidArgumentException if one reserved headers is used.
     */
    public function setHeaders(array $headers): Mail
    {
        // Check reserved headers
        if (count(array_intersect(array_keys($headers), self::RESERVED_HEADERS)) > 0) {
            throw new InvalidArgumentException(sprintf('"%s" are reserved headers, use internal functions instead', implode(', ', self::RESERVED_HEADERS)));
        }

        $headers =
            array_map(
                function ($value) {
                    return (array) $value;
                },
                $headers);
        $this->headers = $headers;

        return $this;
    }

    /**
     * Add additional header.
     *
     * WARNING: header value needs to be encoded before!
     *
     * @param string $name    Name
     * @param string $value   Value
     * @param bool   $replace Replacement if same header present
     *
     * @return static
     * @throws \Berlioz\Mailer\Exception\InvalidArgumentException if reserved header is used.
     */
    public function addHeader(string $name, $value, bool $replace = false): Mail
    {
        // Check reserved headers
        if (in_array($name, self::RESERVED_HEADERS)) {
            throw new InvalidArgumentException(sprintf('"%s" is a reserved header, use internal functions instead', $name));
        }

        if (isset($this->headers[$name]) && $replace === false) {
            $this->headers[$name][] = $value;
        } else {
            $this->headers[$name] = [$value];
        }

        return $this;
    }

    /**
     * Get from address.
     *
     * @return \Berlioz\Mailer\Address|null
     */
    public function getFrom(): ?Address
    {
        return $this->from;
    }

    /**
     * Set from address.
     *
     * @param \Berlioz\Mailer\Address $from
     *
     * @return static
     */
    public function setFrom(Address $from): Mail
    {
        $this->from = $from;

        return $this;
    }

    /**
     * Get to addresses.
     *
     * @return \Berlioz\Mailer\Address[]
     */
    public function getTo(): array
    {
        return $this->to ?? [];
    }

    /**
     * Set to addresses.
     *
     * @param \Berlioz\Mailer\Address[] $to
     *
     * @return static
     */
    public function setTo(array $to): Mail
    {
        $this->to =
            array_filter(
                $to,
                function ($value) {
                    return $value instanceof Address;
                });

        return $this;
    }

    /**
     * Get cc addresses.
     *
     * @return \Berlioz\Mailer\Address[]
     */
    public function getCc(): array
    {
        return $this->cc ?? [];
    }

    /**
     * Set cc addresses.
     *
     * @param \Berlioz\Mailer\Address[] $cc
     *
     * @return static
     */
    public function setCc(array $cc): Mail
    {
        $this->cc =
            array_filter(
                $cc,
                function ($value) {
                    return $value instanceof Address;
                });

        return $this;
    }

    /**
     * Get bcc addresses.
     *
     * @return \Berlioz\Mailer\Address[]
     */
    public function getBcc(): array
    {
        return $this->bcc ?? [];
    }

    /**
     * Set bcc addresses.
     *
     * @param \Berlioz\Mailer\Address[] $bcc
     *
     * @return static
     */
    public function setBcc(array $bcc): Mail
    {
        $this->bcc =
            array_filter(
                $bcc,
                function ($value) {
                    return $value instanceof Address;
                });

        return $this;
    }

    /**
     * Reset recipients.
     *
     * Note: reset arrays to, cc and bcc; from address is keep.
     *
     * @return static
     */
    public function resetRecipients(): Mail
    {
        $this->to = $this->cc = $this->bcc = [];

        return $this;
    }

    /**
     * Get subject.
     *
     * @return string
     */
    public function getSubject(): string
    {
        return $this->subject ?? '';
    }

    /**
     * Set subject.
     *
     * @param string $subject
     *
     * @return static
     */
    public function setSubject(string $subject): Mail
    {
        $this->subject = $subject;

        return $this;
    }

    /**
     * Get text.
     *
     * @return string|null
     */
    public function getText(): ?string
    {
        return $this->text;
    }

    /**
     * Has text ?
     *
     * @return bool
     */
    public function hasText(): bool
    {
        return !empty($this->text);
    }

    /**
     * Set text.
     *
     * @param string $text
     *
     * @return static
     */
    public function setText(string $text): Mail
    {
        $this->text = $text;

        return $this;
    }

    /**
     * Get html.
     *
     * @param bool $minified (default: false)
     *
     * @return string|null
     */
    public function getHtml(bool $minified = false): ?string
    {
        if ($minified) {
            // Save and change PHP configuration value
            $oldPcreRecursionLimit = ini_get('pcre.recursion_limit');
            if (PHP_OS == 'WIN') {
                ini_set('pcre.recursion_limit', '524');
            } else {
                ini_set('pcre.recursion_limit', '16777');
            }

            $regex = <<<'EOD'
%# Collapse whitespace everywhere but in blacklisted elements.
(?>             # Match all whitespans other than single space.
  [^\S ]\s*     # Either one [\t\r\n\f\v] and zero or more ws,
| \s{2,}        # or two or more consecutive-any-whitespace.
) # Note: The remaining regex consumes no text at all...
(?=             # Ensure we are not in a blacklist tag.
  [^<]*+        # Either zero or more non-"<" {normal*}
  (?:           # Begin {(special normal*)*} construct
    <           # or a < starting a non-blacklist tag.
    (?!/?(?:textarea|pre|script)\b)
    [^<]*+      # more non-"<" {normal*}
  )*+           # Finish "unrolling-the-loop"
  (?:           # Begin alternation group.
    <           # Either a blacklist start tag.
    (?>textarea|pre|script)\b
  | \z          # or end of file.
  )             # End alternation group.
)  # If we made it here, we are not in a blacklist tag.
%Six
EOD;

            // Reset PHP configuration value
            ini_set('pcre.recursion_limit', $oldPcreRecursionLimit);

            return preg_replace($regex, ' ', $this->html);
        }

        return $this->html;
    }

    /**
     * Has html ?
     *
     * @return bool
     */
    public function hasHtml(): bool
    {
        return !empty($this->html);
    }

    /**
     * Set html.
     *
     * @param string $html
     *
     * @return Mail
     */
    public function setHtml(string $html): Mail
    {
        $this->html = $html;

        return $this;
    }

    /**
     * Get attachments.
     *
     * @return \Berlioz\Mailer\Attachment[]
     */
    public function getAttachments(): array
    {
        return $this->attachments ?? [];
    }

    /**
     * Set attachments.
     *
     * @param \Berlioz\Mailer\Attachment[] $attachments
     *
     * @return static
     */
    public function setAttachments(array $attachments): Mail
    {
        $this->attachments =
            array_filter(
                $attachments,
                function ($value) {
                    return $value instanceof Attachment;
                });

        return $this;
    }

    /**
     * Add attachment.
     *
     * @param Attachment $attachment
     *
     * @return static
     */
    public function addAttachment(Attachment $attachment): Mail
    {
        $this->attachments[] = $attachment;

        return $this;
    }
}