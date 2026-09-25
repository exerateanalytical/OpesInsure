<?php
namespace App\Filament\Admin\Resources\Tenants;

use App\Application\Tenancy\TenantLifecycleService;use App\Filament\Admin\Concerns\ServiceValidation;use App\Filament\Admin\Resources\Tenants\Pages;use App\Models\Tenant;use BackedEnum;use Filament\Actions;use Filament\Forms;use Filament\Notifications\Notification;use Filament\Resources\Resource;use Filament\Schemas\Schema;use Filament\Support\Icons\Heroicon;use Filament\Tables;use Filament\Tables\Table;use Illuminate\Database\Eloquent\Builder;

final class TenantResource extends Resource
{
    protected static ?string $model=Tenant::class;protected static string|BackedEnum|null $navigationIcon=Heroicon::OutlinedBuildingOffice2;protected static ?string $navigationLabel='Organizations';protected static ?string $modelLabel='organization';protected static ?string $pluralModelLabel='organizations';protected static ?int $navigationSort=10;
    public static function form(Schema $schema):Schema{return $schema->components([
        \Filament\Schemas\Components\Section::make('Legal identity')->columns(2)->schema([
            Forms\Components\Select::make('type')->options(fn(?Tenant$record)=>\App\Application\Tenancy\OrganizationStructureService::tenantTypeOptions()+($record&&$record->type==='AGENCY'?['AGENCY'=>'Agency (legacy)']:[]))->required()->native(false),
            Forms\Components\Select::make('status')->options(['PENDING'=>'Pending review','ACTIVE'=>'Active','SUSPENDED'=>'Suspended','CLOSED'=>'Closed'])->required()->native(false),
            Forms\Components\TextInput::make('legal_name')->required()->maxLength(160),Forms\Components\TextInput::make('trade_name')->maxLength(160),
            Forms\Components\TextInput::make('registration_number')->maxLength(80),Forms\Components\TextInput::make('tax_number')->maxLength(80),
        ]),
        \Filament\Schemas\Components\Section::make('Platform settings')->columns(2)->schema([
            Forms\Components\TextInput::make('slug')->required()->alphaDash()->maxLength(80)->unique(ignoreRecord:true),
            Forms\Components\Select::make('primary_locale')->options(['en'=>'English','fr'=>'Français'])->required()->native(false),
            Forms\Components\TextInput::make('country_code')->default('CM')->required()->length(2),Forms\Components\TextInput::make('currency')->default('XAF')->required()->length(3),
            // REQ-TMP-003: empty = inherit the platform default (Africa/Douala unless changed in Platform settings).
            Forms\Components\Select::make('timezone')->label('Timezone')->options(fn()=>app(\App\Application\Settings\TimezoneCatalogue::class)->options())->searchable()->placeholder('Platform default')->helperText('Business dates for this organization. Branches may override.'),
        ])]);}
    public static function table(Table $table):Table{return $table->columns([
        Tables\Columns\TextColumn::make('legal_name')->label('Legal name')->searchable()->sortable()->description(fn(Tenant$r)=>$r->trade_name),
        Tables\Columns\TextColumn::make('type')->badge()->color(fn(string$state)=>match($state){'CARRIER'=>'info','BROKER'=>'success','AGENCY'=>'warning',default=>'gray'}),
        Tables\Columns\TextColumn::make('registration_number')->label('Registration')->toggleable(),
        Tables\Columns\TextColumn::make('status')->badge()->color(fn(string$state)=>match($state){'ACTIVE'=>'success','PENDING'=>'warning','SUSPENDED'=>'danger',default=>'gray'}),
        Tables\Columns\TextColumn::make('memberships_count')->counts('memberships')->label('Members')->alignEnd(),Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault:true),
    ])->filters([Tables\Filters\SelectFilter::make('type')->options(['BROKER'=>'Broker','CARRIER'=>'Carrier','AGENCY'=>'Agency']),Tables\Filters\SelectFilter::make('status')->options(['PENDING'=>'Pending','ACTIVE'=>'Active','SUSPENDED'=>'Suspended'])])->recordActions([Actions\ViewAction::make(),Actions\EditAction::make(),\App\Filament\Admin\Actions\LetterheadActions::edit('TENANT',fn(Tenant$r)=>$r->id),\App\Filament\Admin\Actions\LetterheadActions::approve('TENANT',fn(Tenant$r)=>$r->id),Actions\Action::make('change_status')->label(fn(Tenant$r)=>match($r->status){'PENDING'=>'Activate','ACTIVE'=>'Suspend','SUSPENDED'=>'Restore',default=>'Change status'})->color(fn(Tenant$r)=>$r->status==='ACTIVE'?'danger':'success')->visible(fn(Tenant$r)=>$r->status!=='CLOSED')->requiresConfirmation()->schema([Forms\Components\Select::make('reason')->options(['LEGAL_VERIFIED'=>'Legal identity verified','COMPLIANCE_HOLD'=>'Compliance hold','ADMINISTRATIVE_RESTORE'=>'Administrative restore'])->required(),Forms\Components\Textarea::make('notes')->required()->minLength(10)])->action(function(Tenant$r,array$data){$to=match($r->status){'PENDING','SUSPENDED'=>'ACTIVE','ACTIVE'=>'SUSPENDED'};if(ServiceValidation::run(fn()=>app(TenantLifecycleService::class)->transition($r,$to,$data['reason'],$data['notes'],auth()->user()))===null)return;Notification::make()->title('Organization status updated')->success()->send();})])->toolbarActions([Actions\CreateAction::make()])->emptyStateHeading('No organizations yet')->emptyStateDescription('Create a broker, carrier or agency after its legal identity has been checked.')->emptyStateIcon(Heroicon::OutlinedBuildingOffice2);}
    public static function getPages():array{return['index'=>Pages\ListTenants::route('/'),'create'=>Pages\CreateTenant::route('/create'),'view'=>Pages\ViewTenant::route('/{record}'),'edit'=>Pages\EditTenant::route('/{record}/edit')];}
    public static function getEloquentQuery():Builder{return parent::getEloquentQuery()->withoutGlobalScopes([]);}
}
