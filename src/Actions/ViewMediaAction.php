<?php

declare(strict_types=1);

namespace Kirantimsina\FileManager\Actions;

use Closure;
use Filament\Actions\Concerns\CanCustomizeProcess;
use Filament\Tables\Actions\Action;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Arr;

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

        // $this->modalHeading(fn (): string => 'View Media');

        // $this->modalSubmitActionLabel('Save');

        $this->successNotificationTitle('Saved!');

        $this->icon('heroicon-m-photo');

        // $this->fillForm(function (Model $record, Table $table): array {
        //     if ($translatableContentDriver = $table->makeTranslatableContentDriver()) {
        //         $data = $translatableContentDriver->getRecordAttributesToArray($record);
        //     } else {
        //         $data = $record->attributesToArray();
        //     }

        //     if ($this->mutateRecordDataUsing) {
        //         $data = $this->evaluate($this->mutateRecordDataUsing, ['data' => $data]);
        //     }

        //     return $data;
        // });

        // $this->action(function (): void {
        //     $this->process(function (array $data, Model $record, Table $table) {
        //         $relationship = $table->getRelationship();

        //         $translatableContentDriver = $table->makeTranslatableContentDriver();

        //         if ($relationship instanceof BelongsToMany) {
        //             $pivot = $record->{$relationship->getPivotAccessor()};

        //             $pivotColumns = $relationship->getPivotColumns();
        //             $pivotData = Arr::only($data, $pivotColumns);

        //             if (count($pivotColumns)) {
        //                 if ($translatableContentDriver) {
        //                     $translatableContentDriver->updateRecord($pivot, $pivotData);
        //                 } else {
        //                     $pivot->update($pivotData);
        //                 }
        //             }

        //             $data = Arr::except($data, $pivotColumns);
        //         }

        //         if ($translatableContentDriver) {
        //             $translatableContentDriver->updateRecord($record, $data);
        //         } else {
        //             $record->update($data);
        //         }
        //     });

        //     $this->success();
        // });
    }

    public function mutateRecordDataUsing(?Closure $callback): static
    {
        $this->mutateRecordDataUsing = $callback;

        return $this;
    }
}
