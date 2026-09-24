<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MasterDataValues\Pages;

use App\Filament\Admin\Resources\MasterDataValues\MasterDataValueResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateMasterDataValue extends CreateRecord
{
    protected static string $resource = MasterDataValueResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $list = \App\Models\MasterData\MasterDataList::findOrFail($data['list_id']);
        $data['domain_code'] = $list->domain_code;
        $data['list_code'] = $list->code;
        $data['code'] = strtoupper($data['code']);
        if ($data['parent_code'] ?? null) {
            [$d, $l] = str_contains((string) $list->parent_list_code, '.') ? explode('.', $list->parent_list_code, 2) : [$list->domain_code, $list->parent_list_code];
            $data['parent_value_id'] = \App\Models\MasterData\MasterDataValue::where(['domain_code' => $d, 'list_code' => $l, 'code' => $data['parent_code']])->value('id');
        }

        return $data;
    }}
