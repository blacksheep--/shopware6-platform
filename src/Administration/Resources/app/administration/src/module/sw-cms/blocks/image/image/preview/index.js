import template from './sw-cms-preview-image.html.twig';
import './sw-cms-preview-image.scss';

const { Component } = Shopware;

// eslint-disable-next-line sw-deprecation-rules/private-feature-declarations
Component.register('sw-cms-preview-image', {
    template,
});
