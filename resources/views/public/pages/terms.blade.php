@extends('public.layout')
@section('title', __('site.terms.title').' — OpesInsure')
@section('description', __('site.terms.lede'))
@section('content')
@include('public.pages.legal', ['key' => 'site.terms'])
@endsection
