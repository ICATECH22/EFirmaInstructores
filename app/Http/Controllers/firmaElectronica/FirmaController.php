<?php

namespace App\Http\Controllers\firmaElectronica;


// use QrCode;
use setasign\Fpdi\Fpdi;
use App\DocumentosFirmar;
use Illuminate\Http\Request;
use Spatie\ArrayToXml\ArrayToXml;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\Controller;
// use BaconQrCode\Encoder\QrCode;
use Illuminate\Support\Facades\Http;
use Vyuldashev\XmlToArray\XmlToArray;
use Illuminate\Support\Facades\Storage;
// use SimpleSoftwareIO\QrCode\Facades\QrCode;

class FirmaController extends Controller {
    
    public function index() {
        // por firmar : documentos donde su correo aparezca en el nodo firmantes
        $email = Auth::user()->email;
        $docsFirmar = DocumentosFirmar::where('status','!=','CANCELADO')
                        ->whereRaw("EXISTS(SELECT TRUE FROM jsonb_array_elements(obj_documento->'firmantes'->'firmante'->0) x 
                        WHERE x->'_attributes'->>'email_firmante' IN ('".$email."') 
                        AND x->'_attributes'->>'firma_firmante' is null)")->get();

        $docsFirmados = DocumentosFirmar::where('status','!=','CANCELADO')
                        ->where('status', 'EnFirma')
                        ->whereRaw("EXISTS(SELECT TRUE FROM jsonb_array_elements(obj_documento->'firmantes'->'firmante'->0) x 
                        WHERE x->'_attributes'->>'email_firmante' IN ('".$email."') 
                        AND x->'_attributes'->>'firma_firmante' <> '')")->get();

        $docsValidados = DocumentosFirmar::where('status', '=', 'VALIDADO')
                        ->whereRaw("EXISTS(SELECT TRUE FROM jsonb_array_elements(obj_documento->'firmantes'->'firmante'->0) x 
                        WHERE x->'_attributes'->>'email_firmante' IN ('".$email."'))")->get();
        
        foreach ($docsFirmar as $value) {
            $value->base64xml = base64_encode($value->documento);
        }
        
        return view('layouts.firmaElectronica.firmaElectronica', compact('docsFirmar', 'email', 'docsFirmados', 'docsValidados'));
    }

    public function update(Request $request) {
        $documento = DocumentosFirmar::where('id', $request->idFile)->first();

        $obj_documento = json_decode($documento->obj_documento, true);
        $obj_documento_interno = json_decode($documento->obj_documento_interno, true);
        foreach ($obj_documento['firmantes']['firmante'][0] as $key => $value) {
            if ($value['_attributes']['curp_firmante'] == $request->curp) {
                $value['_attributes']['fecha_firmado_firmante'] = $request->fechaFirmado;
                $value['_attributes']['no_serie_firmante'] = $request->serieFirmante; 
                $value['_attributes']['firma_firmante'] = $request->firma;
                $obj_documento['firmantes']['firmante'][0][$key] = $value;
            }
        }
        foreach ($obj_documento_interno['firmantes']['firmante'][0] as $key => $value) {
            if ($value['_attributes']['curp_firmante'] == $request->curp) {
                $value['_attributes']['fecha_firmado_firmante'] = $request->fechaFirmado;
                $value['_attributes']['no_serie_firmante'] = $request->serieFirmante; 
                $value['_attributes']['firma_firmante'] = $request->firma;
                $obj_documento_interno['firmantes']['firmante'][0][$key] = $value;
            }
        }

        $array = XmlToArray::convert($documento->documento);
        $array2 = XmlToArray::convert($documento->documento_interno);
        $array['DocumentoChis']['firmantes'] = $obj_documento['firmantes'];
        $array2['DocumentoChis']['firmantes'] = $obj_documento_interno['firmantes'];

        $result = ArrayToXml::convert($obj_documento, [
            'rootElementName' => 'DocumentoChis',
            '_attributes' => [
                'version' => $array['DocumentoChis']['_attributes']['version'],
                'fecha_creacion' => $array['DocumentoChis']['_attributes']['fecha_creacion'],
                'no_oficio' => $array['DocumentoChis']['_attributes']['no_oficio'],
                'dependencia_origen' => $array['DocumentoChis']['_attributes']['dependencia_origen'],
                'asunto_docto' => $array['DocumentoChis']['_attributes']['asunto_docto'],
                'tipo_docto' => $array['DocumentoChis']['_attributes']['tipo_docto'],
                'xmlns' => 'http://firmaelectronica.chiapas.gob.mx/GCD/DoctoGCD',
            ],
        ]);

        $result2 = ArrayToXml::convert($obj_documento_interno, [
            'rootElementName' => 'DocumentoChis',
            '_attributes' => [
                'version' => $array2['DocumentoChis']['_attributes']['version'],
                'fecha_creacion' => $array2['DocumentoChis']['_attributes']['fecha_creacion'],
                'no_oficio' => $array2['DocumentoChis']['_attributes']['no_oficio'],
                'dependencia_origen' => $array2['DocumentoChis']['_attributes']['dependencia_origen'],
                'asunto_docto' => $array2['DocumentoChis']['_attributes']['asunto_docto'],
                'tipo_docto' => $array2['DocumentoChis']['_attributes']['tipo_docto'],
                'xmlns' => 'http://firmaelectronica.chiapas.gob.mx/GCD/DoctoGCD',
            ],
        ]);

        DocumentosFirmar::where('id', $request->idFile)
            ->update([
                'obj_documento' => json_encode($obj_documento),
                'obj_documento_interno' => json_encode($obj_documento_interno),
                'documento' => $result,
                'documento_interno' => $result2 
            ]);

        return redirect()->route('firma.inicio')->with('warning', 'Documento firmado exitosamente!');
    }

    public function sellar(Request $request) {
        $documento = DocumentosFirmar::where('id', $request->txtIdFirmado)->first();

        // dd($documento);
        $xmlBase64 = base64_encode($documento->documento);
        $response = Http::post('https://interopera.chiapas.gob.mx/FirmadoElectronicoDocumentos/SellarXML', [
            'xml_Final' => $xmlBase64
        ]);

        // dd($response->json());

        if ($response->json()['status'] == 1) { //exitoso
            $decode = base64_decode($response->json()['xml']);
            DocumentosFirmar::where('id', $request->txtIdFirmado)
                ->update([
                    'status' => 'VALIDADO',
                    'uuid_sellado' => $response->json()['uuid'],
                    'fecha_sellado' => $response->json()['fecha_Sellado'],
                    'documento' => $decode
                ]);
            return redirect()->route('firma.inicio')->with('warning', 'Documento validado exitosamente!');
        } else {
            return redirect()->route('firma.inicio')->with('danger', 'Ocurrio un error al sellar el documento, por favor intente de nuevo');
        }
    }

    public function generarPDF(Request $request) {
        $documento = DocumentosFirmar::where('id', $request->txtIdGenerar)->first();
        // dd($documento->link_pdf);
        $objeto = json_decode($documento->obj_documento_interno,true);
        // dd($objeto['firmantes']['firmante'][0]);

        $path = storage_path('app/public/uploadFiles/DocumentosFirmas/'.Auth::user()->id.'/'.$documento->nombre_archivo);
        $result = str_replace('\\','/', $path);


        $pdf = new Fpdi();
        // $pdf->addPage('L','Letter');
        $pageCount =  $pdf->setSourceFile($result);
        
        for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) { 
            $tplId = $pdf->importPage($pageNo);
            $pdf->addPage('L','Letter'); //
            $pdf->useTemplate($tplId);
        }

        // $pdf->setSourceFile('C:/xampp/htdocs/instructores/storage/app/public/uploadFiles/DocumentosFirmas/4/LISTA_ASISTENCIA_8E-21-OFIM-CAE-0003.PDF');
        // $tplId = $pdf->importPage(1);
        // $pdf->useTemplate($tplId);
        $pdf->addPage('L','Letter');
        
        // The new content
        $fontSize = '15';
        $fontColor = `255,0,0`;
        $left = 16;
        $top = 30;
        $text = 'Documento';

        $pdf->SetFont("helvetica", "B", 10);
        // $pdf->SetTextColor($fontColor);
        $pdf->Text($left,$top,$text);
        $pdf->Text(16, 60, 'Firmas e informacion identificadora');
        
        $pdf->SetFont("helvetica", '', 7);
        // documento
        $pdf->SetTextColor(98,98,98);
        $pdf->Text(20, 35, 'Nombre del documento:');
        $pdf->Text(20, 40, 'Fecha de constancia:');
        $pdf->Text(20, 45, 'Documento creado por:');
        $pdf->Text(20, 50, 'Numero de paginas:');
        
        $pdf->SetTextColor($fontColor);
        $pdf->Text(80, 35, $objeto['archivo']['_attributes']['nombre_archivo']);
        $pdf->Text(80, 40, $documento->fecha_sellado);
        $pdf->Text(80, 45, $objeto['emisor']['_attributes']['nombre_emisor']);
        $pdf->Text(80, 50, $pageCount);

        // firmas
        $pdf->Text(77, 60, '(Las firmas son unicas para este documento)');
        foreach ($objeto['firmantes']['firmante'][0] as $value) {
            $pdf->SetTextColor(98,98,98);
            $pdf->Text(20, 65, 'Nombre del Firmante:');
            // $pdf->Text(20, 70, 'CURP:');
            $pdf->Text(20, 70, 'Numero de Certificado:');
            $pdf->Text(20, 75, 'Emisor:');
            $pdf->Text(20, 80, 'Firma Electronica:');
            $pdf->Text(20, 93, 'Fecha y hora de Firma:');
            $pdf->Text(20, 98, 'Puesto: ');

            $pdf->SetTextColor($fontColor);
            $pdf->Text(80, 65, $value['_attributes']['nombre_firmante']);
            // $pdf->Text(80, 70, $value['_attributes']['curp_firmante']);
            $pdf->Text(80, 70, $value['_attributes']['no_serie_firmante']);
            $pdf->Text(80, 75, 'SERVICIO DE ADMINISTRACION TRIBUTARIA');
            $pdf->setXY(79, 77);
            $pdf->MultiCell(0, 4, $value['_attributes']['firma_firmante'], 0, 'J', false);
            $pdf->Text(80, 93, $value['_attributes']['fecha_firmado_firmante']);
            $pdf->Text(80, 98, $value['_attributes']['puesto_firmante']);
        }
        // $pdf->Image($image,10,10,-300);
        $locat = storage_path('app/public/qrcode/qrcode.png');
        $location = str_replace('\\','/', $locat);
        // QRcode::png("coded number here", $location);
        \PHPQRCode\QRcode::png("Test", $location, 'L', 10, 0);


        // $pdf->Image("test.png", 40, 10, 20, 20, "png");
        $pdf->Image($location, 16, 185, 20, 20, "png");
        
        $pdf->Text(45, 190, 'Para verificar la integridad de este documento, favor de escanear el codigo QR o visitar el enlace:');
        $pdf->Text(45, 195, 'https://ejemplo.chiapas.gob.mx');


        $pdf->Output('I', $objeto['archivo']['_attributes']['nombre_archivo']);
    }

}
