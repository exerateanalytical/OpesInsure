@extends('public.layout')
@section('title', __('site.privacy.title').' — OpesInsure')
@section('description', __('site.privacy.lede'))
@section('content')
@include('public.pages.legal', ['key' => 'site.privacy'])
@endsection
