<x-layouts::app :title="__('Company profile')">
    <livewire:business.profile-editor :section="request()->route('section')" />
</x-layouts::app>
