<?php

namespace App\Filament\Admin\Pages;

use App\Domain\Catalog\Models\Variation;
use App\Domain\Printing\Models\LabelTemplate;
use App\Domain\Printing\Services\LabelSheet;
use App\Domain\Purchasing\Models\Purchase;
use App\Filament\NavigationGroup;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

/**
 * @property-read Schema $form
 *
 * طباعة الباركود: pick items and how many labels of each, then print on a sheet or a roll.
 * Opened empty, from selected products (?variations=1,2) or from a purchase (?purchase=ID — one label per unit bought).
 */
class PrintLabels extends Page
{
    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Catalog;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedQrCode;

    protected static ?int $navigationSort = 90;

    protected static ?string $slug = 'print-labels';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('labels.print');
    }

    public static function getNavigationLabel(): string
    {
        return __('Print barcode labels');
    }

    public function getTitle(): string
    {
        return __('Print barcode labels');
    }

    public function mount(): void
    {
        $items = [];
        if ($ids = request()->query('variations')) {
            foreach (array_filter(array_map('intval', explode(',', (string) $ids))) as $id) {
                $items[] = ['variation_id' => $id, 'count' => 1];
            }
        }
        if ($purchaseId = request()->integer('purchase')) {
            foreach (Purchase::query()->findOrFail($purchaseId)->items()->get(['variation_id', 'base_qty']) as $item) {
                $items[] = ['variation_id' => $item->variation_id, 'count' => (int) ceil((float) $item->base_qty)];
            }
        }

        $this->form->fill([
            'template_id' => LabelTemplate::query()->orderByDesc('is_default')->value('id'),
            'show' => ['name', 'price', 'barcode'],
            'items' => $items ?: [['variation_id' => null, 'count' => 1]],
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make()->columns(2)->schema([
                Select::make('template_id')->label(__('Label paper'))->required()
                    ->options(fn () => LabelTemplate::query()->orderByDesc('is_default')->pluck('name', 'id')),
                CheckboxList::make('show')->label(__('Print on the label'))->columns(3)->required()
                    ->options(collect(LabelSheet::FIELDS)->mapWithKeys(fn ($f) => [$f => __("label_fields.{$f}")])->all()),
            ]),
            Section::make(__('Items'))->schema([
                Repeater::make('items')->hiddenLabel()->minItems(1)->reorderable(false)
                    ->table([TableColumn::make(__('Product'))->width('75%'), TableColumn::make(__('Number of labels'))])
                    ->schema([
                        Select::make('variation_id')->required()->searchable()
                            ->getSearchResultsUsing(fn (string $search) => self::search($search))
                            ->getOptionLabelUsing(fn ($value) => Variation::query()->with('product')->find($value)?->fullName()),
                        TextInput::make('count')->numeric()->integer()->minValue(1)->maxValue(1000)->required()->default(1),
                    ]),
            ]),
        ]);
    }

    /** @return array<int, string> */
    private static function search(string $search): array
    {
        return Variation::query()->with('product')
            ->where(fn (Builder $q) => $q->where('sku', 'like', "%{$search}%")
                ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$search}%"))
                ->orWhereHas('barcodes', fn ($b) => $b->where('barcode', $search)))
            ->limit(30)->get()->mapWithKeys(fn (Variation $v) => [$v->id => $v->fullName().' — '.$v->sku])->all();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])->id('form')->livewireSubmitHandler('print')
                ->footer([Actions::make([Action::make('print')->label(__('Print'))->icon('heroicon-o-printer')->submit('print')])]),
        ]);
    }

    public function printUrl(): string
    {
        $data = $this->form->getState();
        $items = [];
        foreach ($data['items'] as $row) {
            $items[$row['variation_id']] = ($items[$row['variation_id']] ?? 0) + (int) $row['count'];
        }

        return route('print.labels', ['template' => $data['template_id'], 'show' => $data['show'], 'items' => $items]);
    }

    public function print(): void
    {
        $this->js('window.open('.json_encode($this->printUrl()).', "_blank")');
    }
}
