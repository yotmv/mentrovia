<x-layouts::app :title="__('Edit Article')">
    <section class="w-full">
        @isset($article)
            <livewire:admin.knowledge.article-form :article="$article" />
        @else
            <livewire:admin.knowledge.article-form />
        @endisset
    </section>
</x-layouts::app>
