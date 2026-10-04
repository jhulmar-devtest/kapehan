<?php
/**
 * ThermalPrinter.php
 * Pure plain-text layout. NO ESC/POS alignment bytes at all.
 * Everything is left-aligned with manual space padding.
 * Width 32 = real printable chars for 58mm YICHIP on Windows Generic driver.
 */
class ThermalPrinter
{
    const LF  = "\x0A";
    const INIT = "\x1B\x40";
    const CUT  = "\x1B\x64\x01\x1D\x56\x41\x00";

    private string $buf;
    private int    $w;

    public function __construct(int $width = 32)
    {
        $this->w   = $width;
        $this->buf = self::INIT;
    }

    public function getBuffer(): string { return $this->buf; }
    public function getWidth(): int     { return $this->w; }

    // Blank lines
    public function feed(int $n = 1): static
    {
        $this->buf .= str_repeat(self::LF, $n);
        return $this;
    }

    // Plain left-aligned line (auto-truncate)
    public function line(string $text): static
    {
        $this->buf .= mb_substr($text, 0, $this->w) . self::LF;
        return $this;
    }

    // Centered by padding spaces
    public function center(string $text): static
    {
        $text = mb_substr($text, 0, $this->w);
        $pad  = max(0, (int)(($this->w - mb_strlen($text)) / 2));
        $this->buf .= str_repeat(' ', $pad) . $text . self::LF;
        return $this;
    }

    // Separator
    public function sep(string $char = '-'): static
    {
        $this->buf .= str_repeat($char, $this->w) . self::LF;
        return $this;
    }

    // Label: value  (both on same line, label left, value right)
    // Falls back to two lines if too long
    public function row(string $label, string $value): static
    {
        $label = (string)$label;
        $value = (string)$value;
        $gap   = $this->w - mb_strlen($label) - mb_strlen($value);

        if ($gap >= 1) {
            $this->buf .= $label . str_repeat(' ', $gap) . $value . self::LF;
        } else {
            // Too long — label on its own line, value indented below
            $this->buf .= $label . self::LF;
            $this->buf .= '  ' . $value . self::LF;
        }
        return $this;
    }

    // Amount row  (Label .... P000.00)
    public function amount(string $label, float $val, bool $caps = false): static
    {
        if ($caps) $label = strtoupper($label);
        return $this->row($label, 'P' . number_format($val, 2));
    }

    // Item block: name then "  qty x P000.00"
    public function item(int $qty, string $name, float $amount): static
    {
        // Word-wrap name
        foreach ($this->wrap($name, $this->w) as $l) {
            $this->buf .= $l . self::LF;
        }
        $this->buf .= '  ' . $qty . ' x P' . number_format($amount, 2) . self::LF;
        return $this;
    }

    // Centered word-wrap block
    public function wrapCenter(string $text): static
    {
        foreach ($this->wrap($text, $this->w) as $l) {
            $this->center($l);
        }
        return $this;
    }

    // Left word-wrap block
    public function wrapLeft(string $text, string $prefix = ''): static
    {
        $maxW = $this->w - mb_strlen($prefix);
        foreach ($this->wrap($text, max(10, $maxW)) as $l) {
            $this->buf .= $prefix . $l . self::LF;
        }
        return $this;
    }

    public function cut(): static
    {
        $this->buf .= self::CUT;
        return $this;
    }

    private function wrap(string $text, int $max): array
    {
        $words = explode(' ', $text);
        $lines = [];
        $line  = '';
        foreach ($words as $word) {
            $try = $line === '' ? $word : $line . ' ' . $word;
            if (mb_strlen($try) > $max) {
                if ($line !== '') $lines[] = $line;
                while (mb_strlen($word) > $max) {
                    $lines[] = mb_substr($word, 0, $max);
                    $word    = mb_substr($word, $max);
                }
                $line = $word;
            } else {
                $line = $try;
            }
        }
        if ($line !== '') $lines[] = $line;
        return $lines ?: [''];
    }
}
