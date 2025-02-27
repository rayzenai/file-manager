<?php

declare(strict_types=1);

namespace Kirantimsina\FileManager\Actions;

use Closure;
use Filament\Actions\Concerns\CanCustomizeProcess;
use Filament\Tables\Actions\Action;

class ViewMediaAction extends Action
{
    use CanCustomizeProcess;

    protected ?Closure $mutateRecordDataUsing = null;

    protected ?string $fileField = null;

    // Override the static make method to accept a file field name.
    public static function make(?string $name = null, ?string $fileField = null): static
    {
        $action = parent::make($name);
        $action->fileField = $fileField ?? 'file';

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
