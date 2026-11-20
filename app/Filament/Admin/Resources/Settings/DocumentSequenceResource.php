<?php

namespace App\Filament\Admin\Resources\Settings;

use App\Domain\Foundation\Models\DocumentSequence;
use App\Domain\Shared\Enums\DocumentType;
use App\Filament\Admin\Resources\Settings\Pages\ManageDocumentSequences;
use App\Filament\Concerns\AuthorizesWithPermissions;
use App\Filament\NavigationGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;

/** ترقيم المستندات: prefix and next number per document type — optionally per branch and per year. */
class DocumentSequenceResource extends Resource
{
    use AuthorizesWithPermissions;

    protected static ?string $model = DocumentSequence::class;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Settings;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedHashtag;

    protected static ?int $navigationSort = 20;

    public static function getModelLabel(): string
    {
        return __('Document numbering');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Document numbering');
    }

    protected static function permissionFor(string $action): string|false
    {
        return 'settings.manage';
    }

    public static function preview(?string $prefix, mixed $next, mixed $padding): string
    {
        return ($prefix ?? '').str_pad((string) max(1, (int) $next), max(1, (int) $padding), '0', STR_PAD_LEFT);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Select::make('document_type')->label(__('Document'))->options(DocumentType::class)->required()
                ->disabled(fn (?DocumentSequence $record) => $record !== null)->dehydrated(),
            Select::make('location_id')->label(__('Location'))->relationship('location', 'name')->placeholder(__('All locations'))
                ->disabled(fn (?DocumentSequence $record) => $record !== null)->dehydrated(),
            TextInput::make('year')->label(__('Year'))->numeric()->integer()->minValue(2000)->maxValue(2100)->placeholder(__('Every year'))
                ->disabled(fn (?DocumentSequence $record) => $record !== null)->dehydrated()
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get) => $rule
                    ->where('document_type', $get('document_type') instanceof DocumentType ? $get('document_type')->value : $get('document_type'))
                    ->where('location_id', $get('location_id'))),
            TextInput::make('prefix')->label(__('Prefix'))->maxLength(20)->live(onBlur: true),
            TextInput::make('next_number')->label(__('Next number'))->numeric()->integer()->minValue(1)->required()->live(onBlur: true),
            TextInput::make('padding')->label(__('Digits'))->numeric()->integer()->minValue(1)->maxValue(12)->required()->default(5)->live(onBlur: true),
            Text::make(fn (Get $get) => __('Next document will be numbered').': '.self::preview($get('prefix'), $get('next_number'), $get('padding')))->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('document_type')
            ->columns([
                TextColumn::make('document_type')->label(__('Document'))->badge()->color('gray'),
                TextColumn::make('location.name')->label(__('Location'))->placeholder(__('All locations')),
                TextColumn::make('year')->label(__('Year'))->placeholder(__('Every year')),
                TextColumn::make('next')->label(__('Next number'))->fontFamily('mono')
                    ->state(fn (DocumentSequence $r) => self::preview($r->prefix, $r->next_number, $r->padding)),
            ])
            ->filters([SelectFilter::make('document_type')->label(__('Document'))->options(DocumentType::class)])
            ->recordActions([
                EditAction::make(),
                // the global sequence is the fallback of every document type
                DeleteAction::make()->visible(fn (DocumentSequence $r) => $r->location_id !== null || $r->year !== null),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageDocumentSequences::route('/')];
    }
}
