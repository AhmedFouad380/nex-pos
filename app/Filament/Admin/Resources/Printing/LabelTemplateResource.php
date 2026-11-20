<?php

namespace App\Filament\Admin\Resources\Printing;

use App\Domain\Printing\Models\LabelTemplate;
use App\Domain\Printing\Services\LabelSheet;
use App\Filament\Admin\Resources\Printing\Pages\ManageLabelTemplates;
use App\Filament\Concerns\AuthorizesWithPermissions;
use App\Filament\NavigationGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/** ورق الملصقات: label size and how labels sit on an A4 sheet (or a continuous roll). */
class LabelTemplateResource extends Resource
{
    use AuthorizesWithPermissions;

    protected static ?string $model = LabelTemplate::class;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Settings;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedQrCode;

    protected static ?int $navigationSort = 41;

    public static function getModelLabel(): string
    {
        return __('Label paper');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Label papers');
    }

    protected static function permissionFor(string $action): string|false
    {
        return 'printing.manage';
    }

    public static function form(Schema $schema): Schema
    {
        $mm = fn (string $key, string $label) => TextInput::make("paper.{$key}")->label($label)->numeric()->minValue(0)->suffix('mm')
            ->default(LabelSheet::PAPER[$key]);

        return $schema->components([
            Section::make()->columns(3)->schema([
                TextInput::make('name')->label(__('Name'))->required()->maxLength(255),
                Toggle::make('is_continuous')->label(__('Continuous roll (label printer)'))->live()->inline(false),
                Toggle::make('is_default')->label(__('Default'))->inline(false),
                TextInput::make('description')->label(__('Description'))->columnSpanFull(),
            ]),
            Section::make(__('Label size'))->columns(2)->schema([
                $mm('label_width', __('Width'))->required(),
                $mm('label_height', __('Height'))->required(),
            ]),
            Section::make(__('Sheet layout'))->columns(3)->visible(fn (Get $get) => ! $get('is_continuous'))->schema([
                TextInput::make('paper.columns')->label(__('Labels per row'))->numeric()->integer()->minValue(1)->maxValue(10)->default(3),
                $mm('page_width', __('Printable width'))->default(200),
                $mm('gap_x', __('Gap between columns')),
                $mm('gap_y', __('Gap between rows')),
                $mm('margin_top', __('Top margin')),
                $mm('margin_side', __('Side margin')),
            ]),
        ]);
    }

    public static function keepSingleDefault(LabelTemplate $template): void
    {
        if ($template->is_default) {
            DB::table('label_templates')->where('id', '!=', $template->id)->update(['is_default' => false]);
        }
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('Name')),
                TextColumn::make('size')->label(__('Label size'))
                    ->state(fn (LabelTemplate $r) => LabelSheet::paper($r)['label_width'].' × '.LabelSheet::paper($r)['label_height'].' mm'),
                TextColumn::make('columns')->label(__('Labels per row'))->state(fn (LabelTemplate $r) => $r->is_continuous ? __('Roll') : LabelSheet::paper($r)['columns']),
                IconColumn::make('is_default')->label(__('Default'))->boolean(),
            ])
            ->recordActions([
                EditAction::make()->after(fn (LabelTemplate $record) => self::keepSingleDefault($record)),
                DeleteAction::make()->visible(fn (LabelTemplate $record) => ! $record->is_default),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageLabelTemplates::route('/')];
    }
}
