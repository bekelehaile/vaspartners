<?php

namespace App\Support\Migration;

/**
 * Legacy MVAS staff user id → email (same set as MvasStaffUsersSeeder).
 */
final class MvasStaffLegacyMap
{
    /**
     * @return array<int, string> legacy users.id => email
     */
    public static function emailsByLegacyId(): array
    {
        return [
            1 => 'bekele.haile@ethiotelecom.et',
            2 => 'abayneh.mekonnen@ethiotelecom.et',
            3 => 'million.mekibib@ethiotelecom.et',
            4 => 'samuel.tsehay@ethiotelecom.et',
            5 => 'tamiru.tekaw@ethiotelecom.et',
            6 => 'samrawit.awoke@ethiotelecom.et',
            7 => 'kalkidan.sahle@ethiotelecom.et',
            8 => 'aziza.ali@ethiotelecom.et',
            9 => 'tolasa.deressa@ethiotelecom.et',
            10 => 'amsalu.tadesse@ethiotelecom.et',
            11 => 'misrak.abubeker@ethiotelecom.et',
            12 => 'meskerem.tamene@ethiotelecom.et',
            13 => 'mohamed.saidm@ethiotelecom.et',
            14 => 'amsalu.mollam@ethiotelecom.et',
            15 => 'samuel.dadi@ethiotelcom.et',
            17 => 'biruk.fekade@ethiotelecom.et',
            23 => 'meron.melkamu@ethiotelecom.et',
            24 => 'mohammed.haji@ethiotelecom.et',
            25 => 'dereje.negera@ethiotelecom.et',
            26 => 'kidist.abate@ethiotelecom.et',
            28 => 'selome.tilahun@ethiotelecom.et',
        ];
    }
}
