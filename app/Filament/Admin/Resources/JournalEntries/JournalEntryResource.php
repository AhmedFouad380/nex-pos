<?php

namespace App\Filament\Admin\Resources\JournalEntries;

use App\Domain\Finance\Models\JournalEntry;
use App\Filament\Admin\Resources\JournalEntries\Pages\CreateJournalEntry;
use App\Filament\Admin\Resources\JournalEntries\Pages\ListJournalEntries;
use App\Filament\Admin\Resources\JournalEntries\Pages\ViewJournalEntry;
use App\Filament\Concerns\AuthorizesWithPermissions;
use App\Filament\NavigationGroup;
use App\Filament\Support\AccountOptions;
use App\Filament\Support\UserLocations;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn as FormTableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** General journal: every automatic entry (from documents) and manual entries (قيود اليومية). */
class JournalEntryResource extends Resource
{
    use AuthorizesWithPermissions;

    protected static ?string $model = JournalEntry::class;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Finance;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getModelLabel(): string
    {
        return __('Journal entry');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Journal entries');
    }

    protected static function permissionFor(string $action): string|false
    {
        return match ($action) {
            'viewAny', 'view' => 'journal.view',
            'create' => 'journal.manual',
            default => false, // entries are never edited or deleted — they are reversed
        };
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columnSpanFull()->columns(3)->schema([
                DatePicker::make('entry_date')->label(__('Date'))->required()->default(now()),
                TextInput::make('description')->label(__('Description'))->required()->maxLength(255)->columnSpan(2),
                Select::make('location_id')->label(__('Location'))->options(fn () => UserLocations::options()),
            ]),
            Section::make(__('Lines'))->columnSpanFull()->schema([
                Repeater::make('lines')->hiddenLabel()->minItems(2)->defaultItems(2)->reorderable(false)
                    ->table([
                        FormTableColumn::make(__('Account'))->width('40%'),
                        FormTableColumn::make(__('Debit')),
                        FormTableColumn::make(__('Credit')),
                        FormTableColumn::make(__('Memo')),
                    ])
                    ->schema([
                        Select::make('account_id')->options(fn () => AccountOptions::manual())->searchable()->required(),
                        TextInput::make('debit')->numeric()->minValue(0)->live(onBlur: true),
                        TextInput::make('credit')->numeric()->minValue(0)->live(onBlur: true),
                        TextInput::make('memo')->maxLength(255),
                    ]),
                Text::make(fn (Get $get) => self::totalsText($get('lines') ?? [])),
            ]),
        ]);
    }

    public static function totalsText(array $lines): string
    {
        $debit = array_sum(array_map(fn ($l) => (float) ($l['debit'] ?? 0), $lines));
        $credit = array_sum(array_map(fn ($l) => (float) ($l['credit'] ?? 0), $lines));

        return __('Debit').': '.number_format($debit, 2).' — '.__('Credit').': '.number_format($credit, 2)
            .' — '.(abs($debit - $credit) < 0.00005 ? __('Balanced') : __('Difference').': '.number_format($debit - $credit, 2));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columnSpanFull()->columns(4)->schema([
                TextEntry::make('number')->label(__('Number'))->weight('bold')->copyable(),
                TextEntry::make('entry_date')->label(__('Date'))->date(),
                TextEntry::make('source_type')->label(__('Source'))
                    ->formatStateUsing(fn (?string $state) => $state ? __('statement.kinds.'.$state) : __('Manual entry'))
                    ->placeholder(__('Manual entry')),
                TextEntry::make('creator.name')->label(__('By'))->placeholder('—'),
                TextEntry::make('description')->label(__('Description'))->columnSpanFull(),
                TextEntry::make('reversedEntry.number')->label(__('Reverses'))->placeholder('—')
                    ->url(fn (JournalEntry $record) => $record->reversed_entry_id ? self::getUrl('view', ['record' => $record->reversed_entry_id]) : null),
            ]),
            Section::make(__('Lines'))->columnSpanFull()->schema([
                RepeatableEntry::make('lines')->hiddenLabel()
                    ->table([TableColumn::make(__('Account')), TableColumn::make(__('Contact')), TableColumn::make(__('Debit')),
                        TableColumn::make(__('Credit')), TableColumn::make(__('Memo'))])
                    ->schema([
                        TextEntry::make('account.name')->formatStateUsing(fn ($state, $record) => $record->account->code.' — '.$state),
                        TextEntry::make('contact.name')->placeholder(''),
                        TextEntry::make('debit')->numeric(2)->formatStateUsing(fn ($state) => (float) $state ? number_format((float) $state, 2) : ''),
                        TextEntry::make('credit')->numeric(2)->formatStateUsing(fn ($state) => (float) $state ? number_format((float) $state, 2) : ''),
                        TextEntry::make('memo')->placeholder(''),
                    ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withSum('lines as total', 'debit'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('number')->label(__('Number'))->searchable()->weight('bold'),
                TextColumn::make('entry_date')->label(__('Date'))->date()->sortable(),
                TextColumn::make('description')->label(__('Description'))->searchable()->wrap(),
                TextColumn::make('source_type')->label(__('Source'))->badge()->color('gray')
                    ->formatStateUsing(fn (?string $state) => __('statement.kinds.'.$state))->placeholder(__('Manual entry')),
                TextColumn::make('total')->label(__('Amount'))->numeric(2),
                IconColumn::make('reversed_entry_id')->label(__('Reversal'))->boolean()
                    ->state(fn (JournalEntry $record) => $record->reversed_entry_id !== null)->toggleable(),
            ])
            ->filters([
                TernaryFilter::make('is_manual')->label(__('Manual entry')),
                Filter::make('period')->schema([
                    DatePicker::make('from')->label(__('From')),
                    DatePicker::make('to')->label(__('To')),
                ])->query(fn (Builder $query, array $data) => $query
                    ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('entry_date', '>=', $d))
                    ->when($data['to'] ?? null, fn ($q, $d) => $q->whereDate('entry_date', '<=', $d))),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListJournalEntries::route('/'),
            'create' => CreateJournalEntry::route('/create'),
            'view' => ViewJournalEntry::route('/{record}'),
        ];
    }
}
