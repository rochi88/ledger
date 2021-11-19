<?php

namespace App\Models\Messages\Ledger;

use App\Exceptions\Breaker;
use App\Helpers\Merge;
use App\Models\Messages\Message;

class Domain extends Message
{
    public string $code;
    public string $currencyDefault;
    /**
     * @var mixed
     */
    public $extra;
    public array $names = [];
    public ?string $revision = null;
    public bool $subJournals;
    public string $toCode;

    /**
     * @inheritdoc
     */
    public static function fromRequest(array $data, int $opFlags): self
    {
        $domain = new static();
        if ($data['code'] ?? false) {
            $domain->code = $data['code'];
        }
        if (isset($data['names'])) {
            $domain->names = Name::fromRequestList($data['names'], $opFlags, 1);
        }
        $domain->subJournals = $data['subJournals'] ?? false;
        if (isset($data['currency'])) {
            $domain->currencyDefault = strtoupper($data['currency']);
        }
        if (isset($data['extra'])) {
            $domain->extra = $data['extra'];
        }
        if ($opFlags & self::OP_UPDATE) {
            if (isset($data['revision'])) {
                $domain->revision = $data['revision'];
            }
            if (isset($data['toCode'])) {
                $domain->toCode = strtoupper($data['toCode']);
            }
        }
        if ($opFlags & self::FN_VALIDATE) {
            $domain->validate($opFlags);
        }

        return $domain;
    }

    /**
     * @inheritdoc
     */
    public function validate(int $opFlags): self
    {
        $errors = [];
        if (!isset($this->code)) {
            $errors[] = 'the code property is required';
        }
        if ($opFlags & self::OP_ADD && count($this->names) === 0) {
            $errors[] = 'A non-empty names property is required';
        }
        if ($opFlags & self::OP_UPDATE && !isset($this->revision)) {
            $errors[] = 'A revision code is required';
        }
        try {
            foreach ($this->names as $name) {
                $name->validate($opFlags);
            }
        } catch (Breaker $exception) {
            Merge::arrays($errors, $exception->getErrors());
        }
        if (count($errors)) {
            throw Breaker::withCode(Breaker::BAD_REQUEST, $errors);
        }
        return $this;
    }
}
