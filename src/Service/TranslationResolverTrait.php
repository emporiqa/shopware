<?php

declare(strict_types=1);

namespace Emporiqa\ShopwarePlugin\Service;

trait TranslationResolverTrait
{
    /**
     * Resolve a translated string field for a given language, with fallback to default context.
     */
    private function getTranslatedString(object $entity, string $field, string $languageId): string
    {
        $own = $this->getOwnTranslatedString($entity, $field, $languageId);
        if ($own !== '') {
            return $own;
        }

        if (method_exists($entity, 'getTranslation')) {
            $value = $entity->getTranslation($field);
            if (\is_string($value) && $value !== '') {
                return $value;
            }
        }

        $getter = 'get' . ucfirst($field);
        if (method_exists($entity, $getter)) {
            $value = $entity->$getter();
            return \is_string($value) ? $value : '';
        }

        return '';
    }

    /**
     * The value the given language's own translation holds for the field, without
     * any fallback to other languages.
     */
    private function getOwnTranslatedString(object $entity, string $field, string $languageId): string
    {
        if ($languageId === '' || !method_exists($entity, 'getTranslations')) {
            return '';
        }

        $translations = $entity->getTranslations();
        if ($translations === null) {
            return '';
        }

        $getter = 'get' . ucfirst($field);
        foreach ($translations as $translation) {
            if (method_exists($translation, 'getLanguageId') && $translation->getLanguageId() === $languageId && method_exists($translation, $getter)) {
                $value = $translation->$getter();

                return \is_string($value) ? $value : '';
            }
        }

        return '';
    }

    /**
     * Page title as the storefront shows it: the language's own SEO meta title, else
     * its own name; only a language without any translation of its own falls back to
     * the default language (a German page must not carry the English meta title).
     */
    private function getTranslatedTitle(object $entity, string $languageId): string
    {
        $ownMetaTitle = $this->getOwnTranslatedString($entity, 'metaTitle', $languageId);
        if ($ownMetaTitle !== '') {
            return $ownMetaTitle;
        }

        $ownName = $this->getOwnTranslatedString($entity, 'name', $languageId);
        if ($ownName !== '') {
            return $ownName;
        }

        $metaTitle = $this->getTranslatedString($entity, 'metaTitle', $languageId);

        return $metaTitle !== '' ? $metaTitle : $this->getTranslatedString($entity, 'name', $languageId);
    }
}
