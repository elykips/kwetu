@props([
'label'=>'',
'name'=>'',
])

<div class="my-2">
    <label for="{{$name}}">{{$label}}</label>
    <input type="text" wire:model="{{$name}}" {!! $attributes->merge([
    'class' =>
    'block mt-1 w-full border-slate-300 rounded-md shadow-sm text-slate-900 sm:text-sm focus:ring-info-500
    focus:border-info-500 disabled:opacity-50 dark:border-slate-500 dark:bg-slate-800 dark:placeholder-slate-500
    dark:text-slate-200 dark:focus:ring-info-500 dark:focus:border-info-500 dark:focus:placeholder-slate-600']) !!}>
    @error($name)
    <div class="text-danger-500 mt-1">{{ $message }} </div>
    @enderror
</div>

{{--
For using this component you have to pass 2 value for label and name.
eg.
<x-form.impinput label="Country" name="country" />
if any error it will also shown condition is user same name in controller.
--}}