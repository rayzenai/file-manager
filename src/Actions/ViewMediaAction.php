<?php

declare(strict_types=1);

namespace Kirantimsina\FileManager\Actions;

use Closure;
use Filament\Actions\Action;
use Filament\Actions\Concerns\CanCustomizeProcess;

class ViewMediaAction extends Action
{
    use CanCustomizeProcess;

    protected ?Closure $mutateRecordDataUsing = null;

    protected ?string $fileField = null;

    protected ?string $counterField = null;

    // Override the static make method to accept a file field name.
    public static function make(?string $name = null, ?string $fileField = null, ?string $counterField = null): static
    {
        $action = parent::make($name);
        $action->fileField = $fileField ?? 'file';

        $action->counterField = $counterField ?? null;

        return $action;
    }

    public static function getDefaultName(): ?string
    {
        return 'view-media';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Media');

        $this->url(function ($record) {

            if ($this->counterField) {
                return $record->viewPageUrl(field: $this->fileField, counter: $this->counterField);
            }

            return $record->viewPageUrl($this->fileField);

        }, true);

        $this->successNotificationTitle('Saved!');

        $this->icon('heroicon-m-photo');

    }

    public function mutateRecordDataUsing(?Closure $callback): static
    {
        $this->mutateRecordDataUsing = $callback;

        return $this;
    }
}
