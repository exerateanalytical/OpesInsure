<?php

declare(strict_types=1);

namespace App\Filament\Shared\Concerns;

use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** For ListRecords pages: clicking a row opens the resource's view page. */
trait OpensViewPage
{
    public function table(Table $table): Table
    {
        return parent::table($table)
            ->recordUrl(fn (Model $record): ?string => static::getResource()::hasPage('view') && static::getResource()::canView($record)
                ? static::getResource()::getUrl('view', ['record' => $record])
                : null);
    }
}
