<?php

namespace App\Filament\Resources\Pages\Schemas;

use App\Filament\Support\Fields;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PageForm
{
    public const SECTION_LABELS = [
        'page' => 'General page (About, Contact...)',
        'policy' => 'Policy (Privacy, Terms...)',
        'faq' => 'FAQ (title = question, content = answer)',
        'solution' => 'Industry solution',
        'service' => 'Service',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Content')->columns(2)->schema([
                Fields::slugFrom(TextInput::make('title')->required()->maxLength(200)),
                Fields::slug(220),
                Select::make('section')->options(self::SECTION_LABELS)->required()->default('page')->native(false),
                TextInput::make('sort_order')->label('Display order')->numeric()->minValue(0)->default(0)->required(),
                Textarea::make('excerpt')->rows(2)->maxLength(500)->columnSpanFull(),
                RichEditor::make('content')
                    ->columnSpanFull()
                    // No file attachments, code blocks or colour/alignment styles: output is sanitized on save anyway.
                    ->toolbarButtons([
                        ['bold', 'italic', 'underline', 'strike', 'link'],
                        ['h2', 'h3'],
                        ['blockquote', 'bulletList', 'orderedList', 'table'],
                        ['undo', 'redo'],
                    ])
                    ->helperText('Scripts, iframes, styles and event handlers are stripped when you save.'),
            ]),
            Section::make('SEO')->columns(2)->collapsible()->schema([
                TextInput::make('meta_title')->maxLength(160),
                TextInput::make('meta_description')->maxLength(320),
            ]),
            Section::make('Publication')->schema([
                Select::make('status')->options([
                    'draft' => 'Draft (hidden)',
                    'published' => 'Published (visible on the website)',
                ])->default('draft')->required()->native(false)
                    ->helperText('Draft pages are never returned by the public API.'),
            ]),
        ]);
    }
}
