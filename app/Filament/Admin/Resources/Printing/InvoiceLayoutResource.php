<?php

namespace App\Filament\Admin\Resources\Printing;

use App\Domain\Printing\Enums\PaperSize;
use App\Domain\Printing\Models\InvoiceLayout;
use App\Domain\Printing\Services\PrintLayout;
use App\Domain\Sales\Models\Sale;
use App\Filament\Admin\Resources\Printing\Pages\ManageInvoiceLayouts;
use App\Filament\Concerns\AuthorizesWithPermissions;
use App\Filament\NavigationGroup;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/** تصميمات الفواتير: paper size, what to show, header / footer text and renamed captions. */
class InvoiceLayoutResource extends Resource
{
    use AuthorizesWithPermissions;

    protected static ?string $model = InvoiceLayout::class;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Settings;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?int $navigationSort = 40;

    public static function getModelLabel(): string
    {
        return __('Invoice layout');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Invoice layouts');
    }

    protected static function permissionFor(string $action): string|false
    {
        return 'printing.manage';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(3)->schema([
                TextInput::make('name')->label(__('Name'))->required()->maxLength(255),
                Select::make('paper')->label(__('Paper'))->options(PaperSize::class)->required()->default(PaperSize::A4),
                Toggle::make('is_default')->label(__('Default for its paper size'))->inline(false),
            ]),
            Section::make(__('What to print'))->columns(3)->schema(
                array_map(fn (string $toggle) => Toggle::make("settings.{$toggle}")->label(__("printing.toggles.{$toggle}"))
                    ->default(PrintLayout::TOGGLES[$toggle]), array_keys(PrintLayout::TOGGLES)),
            ),
            Section::make(__('Header and footer'))->columns(2)->schema([
                Textarea::make('settings.header_text')->label(__('Text under the business name'))->rows(3),
                Textarea::make('settings.footer_text')->label(__('Footer text'))->rows(3)->placeholder(__('Thank you for your visit')),
            ]),
            Section::make(__('Captions'))->description(__('Leave empty to use the default wording.'))->collapsed()->columns(4)->schema(
                array_map(fn (string $key) => TextInput::make("settings.labels.{$key}")->label(__("printing.labels.{$key}"))
                    ->placeholder(__("printing.labels.{$key}"))->maxLength(60), PrintLayout::LABELS),
            ),
        ]);
    }

    /** Only one default per kind (invoice papers / receipt papers). */
    public static function keepSingleDefault(InvoiceLayout $layout): void
    {
        if (! $layout->is_default) {
            return;
        }
        $thermal = [PaperSize::Thermal80->value, PaperSize::Thermal58->value];
        $papers = in_array($layout->paper->value, $thermal, true) ? $thermal : [PaperSize::A4->value, PaperSize::A5->value];
        DB::table('invoice_layouts')->whereIn('paper', $papers)->where('id', '!=', $layout->id)->update(['is_default' => false]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('Name')),
                TextColumn::make('paper')->label(__('Paper'))->badge(),
                IconColumn::make('is_default')->label(__('Default'))->boolean(),
            ])
            ->recordActions([
                Action::make('preview')->label(__('Preview'))->icon('heroicon-o-eye')->color('gray')
                    ->visible(fn () => Sale::query()->whereNotNull('invoice_no')->exists())
                    ->url(fn (InvoiceLayout $record) => route('print.sale', [Sale::query()->whereNotNull('invoice_no')->latest('id')->value('id'),
                        'layout' => $record->id, 'print' => 0]), shouldOpenInNewTab: true),
                EditAction::make()->after(fn (InvoiceLayout $record) => self::keepSingleDefault($record)),
                DeleteAction::make()->visible(fn (InvoiceLayout $record) => ! $record->is_default
                    && ! DB::table('locations')->where('invoice_layout_id', $record->id)->orWhere('receipt_layout_id', $record->id)->exists()),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageInvoiceLayouts::route('/')];
    }
}
