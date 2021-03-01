<?php declare(strict_types=1);

namespace MetaTags\Form;

use Laminas\Form\Element;
use Laminas\Form\Fieldset;
use Omeka\Form\Element\Asset;

class SiteSettingsFieldset extends Fieldset
{
    /**
     * @var string
     */
    protected $label = 'Meta Tags'; // @translate

    public function init(): void
    {
        $this
            ->add([
                'name' => 'metatags_enabled',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Append Open Graph and Twitter tags', // @translate
                ],
                'attributes' => [
                    'id' => 'metatags_enabled',
                ],
            ])

            ->add([
                'name' => 'metatags_og_type',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Open Graph type', // @translate
                    'value_options' => [
                        'og_type' => 'Simple og:type', // @translate
                        'rdf_class' => 'Resource class', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'metatags_og_type',
                    'value' => 'og_type',
                ],
            ])

            ->add([
                'name' => 'metatags_twitter_domain',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Twitter domain', // @translate
                ],
                'attributes' => [
                    'id' => 'metatags_twitter_domain',
                    'placeholder' => 'My site',
                ],
            ])
            ->add([
                'name' => 'metatags_twitter_site',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Twitter site', // @translate
                ],
                'attributes' => [
                    'id' => 'metatags_twitter_site',
                    'placeholder' => '@my-site',
                ],
            ])
            ->add([
                'name' => 'metatags_twitter_creator',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Twitter creator account', // @translate
                ],
                'attributes' => [
                    'id' => 'metatags_twitter_creator',
                    'placeholder' => '@twitter-me',
                ],
            ])

            ->add([
                'name' => 'metatags_media_mode',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Image to use', // @translate
                    'value_options' => [
                        'none' => 'No media', // @translate
                        'static' => 'Selected asset below', // @translate
                        'thumbnail_static' => 'Resource thumbnail or selected asset for page', // @translate
                        'thumbnail' => 'Resource thumbnail or first image', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'metatags_media_mode',
                    'value' => 'thumbnail_static',
                ],
            ])
            ->add([
                'name' => 'metatags_media_asset',
                'type' => Asset::class,
                'options' => [
                    'label' => 'Default image', // @translate
                ],
                'attributes' => [
                    'id' => 'metatags_media_asset',
                ],
            ])
            ->add([
                'name' => 'metatags_media_derivative',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Derivative type', // @translate
                    'info' => 'When a thumbnail exists, the size is not taken in account.', // @translate
                    'value_options' => [
                        'original' => 'Original', // @translate
                        'large' => 'Large', // @translate
                        'medium' => 'Medium', // @translate
                        'square' => 'Square', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'metatags_media_derivative',
                    'value' => 'large',
                ],
            ])
        ;
    }
}
