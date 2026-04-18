<?php

namespace Nudelsalat\Tests\Support;

class FakeQuestioner extends \Nudelsalat\Migrations\Questioner
{
    public function __construct(
        private bool $renameModelAnswer = true,
        private bool $renameFieldAnswer = true,
        private mixed $defaultAnswer = 'default-value'
    ) {
        parent::__construct();
    }

    public function askRenameModel(string $oldName, string $newName): bool
    {
        return $this->renameModelAnswer;
    }

    public function askRenameField(string $modelName, string $oldName, string $newName): bool
    {
        return $this->renameFieldAnswer;
    }

    public function askDefault(string $modelName, string $fieldName): mixed
    {
        return $this->defaultAnswer;
    }
}
