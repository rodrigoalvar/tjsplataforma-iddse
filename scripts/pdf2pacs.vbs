Option Explicit

' Habilitar manejo de errores GLOBAL
On Error Resume Next

Dim FSO,FSOtxt, FLD, FLDtxt, FIL, shl, ReadTXTmsj, DCMrecord
Dim PDFFolder
dim InputFile, dcmodifycmd, dcmodifydate, dcmsendtopacs, pdfmodify
dim NombreFile,tmpStr,substrtoFind,nombreid,nombretxt,pdfbkp,large
dim IDstring, ID, NomDir, NomPDF, NomDCM
dim IDOK, msjRISFolder, FolderExists, PDFfile
Dim NumAcceso, Modalidad, MedReferente, NomPaciente, IDPaciente, NomEquipo, FechaProc, HoraProc
Dim DescProc, FechaNacPac, Sexo, DescProcReq, Sent, aetitle, ordercontrol, ordertype

	'Change as needed
	PDFFolder = "D:\tjs\pdf2dcmin\"
	msjRISFolder = "D:\tjs\msjRIStxt\"
	pdfbkp = "d:\tjs\pdfbkp\"
	idok=false

	'Create the filesystem object
	Set FSO = CreateObject("Scripting.FileSystemObject")
	Set shl = CreateObject("WScript.Shell")
	'Get a reference to the folder you want to search
	set FLD = FSO.GetFolder(PDFFolder)
	set FLDtxt = FSO.GetFolder(msjRISFolder)
	
'	'loop through the folder and get the file names
	For Each Fil In FLD.Files
		If LCase(Right(Fil.Path, 4)) = ".pdf" Then
			Err.Clear  ' Limpiar cualquier error previo
			
			'MsgBox lcase(left(Fil.Name,8))
			nombreid=lcase(left(Fil.Name,8))
			nombrefile=fil.path
			NomPDF=fil.name
			
			if not FSO.FileExists("d:\tjs\pdfbkp\"& NomPDF) then 
				if lcase(right(nombreid, 1)) = "_" then
					nombreid= lcase(left(Fil.Name,7))
					pdfmodify="exiftool -Title=""TANJOUSOFT"" " & "-Author=""TANJOUSOFT"" " & "-Subject=""www.tanjousoft.com.ar"" " & NombreFile & " -overwrite_original"
					shl.Run pdfmodify,1,True
					shl.Run("pdf2dcm +pi " & nombreid & " " & nombrefile & " d:\tjs\pdf2dcmout\" & nombreid & ".dcm")
					NomDCM="d:\tjs\pdf2dcmout\" & nombreid & ".dcm"
					call msjRISnom 'finds txt name to open
					'call MsjReader 'opens txt to get procedure date
					
					dcmodifycmd="dcmodify -i ""(0008,1030)=INFORME "" " & NomDCM
					dcmodifydate="dcmodify -m ""(0008,0020)=" & FechaProc & """ " & NomDCM
					'msgbox dcmodifydate
					'msgox dcmodifycmd
					dcmsendtopacs="dcmsend -v 192.168.0.253 4006 -dn -nh -aec IDDSEPACS "& NomDCM
					shl.Run dcmodifycmd,1,True
					shl.Run dcmodifydate,1,true 
					shl.Run dcmsendtopacs,1,True
					
					' Mover archivo TXT con verificación
					if FSO.FileExists(nombretxt) Then
						Err.Clear
						Call MoverArchivoSeguro(nombretxt, "d:\tjs\msjRISbkp\" & FSO.GetFileName(nombretxt))
						If Err.Number <> 0 Then
							MsgBox "ERROR en bucle principal (TXT):" & vbCrLf & _
							       "Archivo: " & FSO.GetFileName(nombretxt) & vbCrLf & _
							       "Error: " & Err.Description & " (" & Err.Number & ")", _
							       vbExclamation, "Error Línea ~55"
							Err.Clear
						End If
					end If
					
					' Mover archivo PDF con verificación
					if FSO.FileExists(NombreFile) Then
						Err.Clear
						Call MoverArchivoSeguro(NombreFile, "d:\tjs\pdfbkp\" & NomPDF)
						If Err.Number <> 0 Then
							MsgBox "ERROR en bucle principal (PDF):" & vbCrLf & _
							       "Archivo: " & NomPDF & vbCrLf & _
							       "Origen: " & NombreFile & vbCrLf & _
							       "Destino: " & "d:\tjs\pdfbkp\" & NomPDF & vbCrLf & _
							       "Error: " & Err.Description & " (" & Err.Number & ")", _
							       vbExclamation, "Error Línea ~67"
							Err.Clear
						End If
					end If
				Else
					nombreid= lcase(left(Fil.Name,8))
					pdfmodify="exiftool -Title=""TANJOUSOFT"" " & "-Author=""TANJOUSOFT"" " & "-Subject=""www.tanjousoft.com.ar"" " & NombreFile & " -overwrite_original"
					shl.Run pdfmodify,1,True
					shl.Run("pdf2dcm +pi " & nombreid & " " & nombrefile & " d:\tjs\pdf2dcmout\" & nombreid & ".dcm")
					NomDCM="d:\tjs\pdf2dcmout\" & nombreid & ".dcm"
					call msjRISnom	'finds txt name to open
					'call MsjReader	'opens txt to get procedure date
					'dcmodifycmd="dcmodify -i ""(0008,1030)=INFORME "" -m ""(0040,0002)="" "& FechaProc & """ " &  NomDCM				
					dcmodifycmd="dcmodify -i ""(0008,1030)=INFORME "" " & NomDCM
					dcmodifydate="dcmodify -m ""(0008,0020)=" & FechaProc & """ " & NomDCM
					'msgbox dcmodifydate
					'msgbox dcmodifycmd
					dcmsendtopacs="dcmsend -v 192.168.0.253 4006 -dn -nh -aec IDDSEPACS "& NomDCM
					shl.Run dcmodifycmd,1,True
					shl.Run dcmodifydate,1,True
					shl.Run dcmsendtopacs,1,True

					' Mover archivo TXT con verificación
					if FSO.FileExists(nombretxt) Then
						Err.Clear
						Call MoverArchivoSeguro(nombretxt, "d:\tjs\msjRISbkp\" & FSO.GetFileName(nombretxt))
						If Err.Number <> 0 Then
							MsgBox "ERROR en bucle principal (TXT):" & vbCrLf & _
							       "Archivo: " & FSO.GetFileName(nombretxt) & vbCrLf & _
							       "Error: " & Err.Description & " (" & Err.Number & ")", _
							       vbExclamation, "Error Línea ~96"
							Err.Clear
						End If
					end If
					
					' Mover archivo PDF con verificación
					if FSO.FileExists(NombreFile) Then
						Err.Clear
						Call MoverArchivoSeguro(NombreFile, "d:\tjs\pdfbkp\" & NomPDF)
						If Err.Number <> 0 Then
							MsgBox "ERROR en bucle principal (PDF):" & vbCrLf & _
							       "Archivo: " & NomPDF & vbCrLf & _
							       "Origen: " & NombreFile & vbCrLf & _
							       "Destino: " & "d:\tjs\pdfbkp\" & NomPDF & vbCrLf & _
							       "Error: " & Err.Description & " (" & Err.Number & ")", _
							       vbExclamation, "Error Línea ~108"
							Err.Clear
						End If
					end If			
				end if		
			else 
				Err.Clear
				FSO.DeleteFile "d:\tjs\pdf2dcmin\" & NomPDF
				If Err.Number <> 0 Then
					MsgBox "ERROR al eliminar archivo duplicado:" & vbCrLf & _
					       "Archivo: " & NomPDF & vbCrLf & _
					       "Error: " & Err.Description & " (Código: " & Err.Number & ")", _
					       vbExclamation, "Error Línea ~120"
					Err.Clear
				End If
			end If
	
		end if
	Next

' Nueva función para mover archivos de forma segura
Sub MoverArchivoSeguro(origen, destino)
	Dim intentos, esperaMs, nombreArchivo
	Dim archivoDestino
	intentos = 0
	esperaMs = 100
	nombreArchivo = FSO.GetFileName(origen)
	
	Do While intentos < 3
		Err.Clear
		
		' Si el archivo destino ya existe, eliminarlo primero
		If FSO.FileExists(destino) Then
			FSO.DeleteFile destino, True  ' True = forzar eliminación
			If Err.Number <> 0 Then
				MsgBox "ERROR al eliminar archivo destino existente:" & vbCrLf & _
				       "Archivo: " & nombreArchivo & vbCrLf & _
				       "Destino: " & destino & vbCrLf & _
				       "Error: " & Err.Description & " (Código: " & Err.Number & ")" & vbCrLf & _
				       "Intento: " & (intentos + 1) & " de 3", _
				       vbExclamation, "Error al Eliminar Destino"
				Err.Clear
			End If
			WScript.Sleep esperaMs  ' Esperar un poco
		End If
		
		' Verificar que se eliminó correctamente
		If Not FSO.FileExists(destino) Then
			' Ahora intentar mover el archivo
			Err.Clear
			FSO.MoveFile origen, destino
			
			' Si el movimiento fue exitoso, salir
			If Err.Number = 0 Then
				Exit Sub
			Else
				MsgBox "ERROR al mover archivo:" & vbCrLf & _
				       "Archivo: " & nombreArchivo & vbCrLf & _
				       "Origen: " & origen & vbCrLf & _
				       "Destino: " & destino & vbCrLf & _
				       "Error: " & Err.Description & " (Código: " & Err.Number & ")" & vbCrLf & _
				       "Intento: " & (intentos + 1) & " de 3", _
				       vbExclamation, "Error al Mover Archivo"
				Err.Clear
			End If
		End If
		
		' Si llegamos aquí, hubo un error. Intentar método alternativo
		Err.Clear
		FSO.CopyFile origen, destino, True  ' True = sobrescribir
		
		If Err.Number = 0 Then
			' La copia fue exitosa, eliminar origen
			WScript.Sleep esperaMs
			Err.Clear
			FSO.DeleteFile origen, True
			If Err.Number = 0 Then
				Exit Sub  ' Éxito total
			Else
				MsgBox "ERROR al eliminar archivo origen después de copiar:" & vbCrLf & _
				       "Archivo: " & nombreArchivo & vbCrLf & _
				       "Error: " & Err.Description & " (Código: " & Err.Number & ")", _
				       vbExclamation, "Error al Eliminar Origen"
				Err.Clear
			End If
		Else
			MsgBox "ERROR al copiar archivo:" & vbCrLf & _
			       "Archivo: " & nombreArchivo & vbCrLf & _
			       "Origen: " & origen & vbCrLf & _
			       "Destino: " & destino & vbCrLf & _
			       "Error: " & Err.Description & " (Código: " & Err.Number & ")" & vbCrLf & _
			       "Intento: " & (intentos + 1) & " de 3", _
			       vbExclamation, "Error al Copiar Archivo"
			Err.Clear
		End If
		
		intentos = intentos + 1
		WScript.Sleep esperaMs * intentos  ' Espera incremental
	Loop
	
	' Si después de 3 intentos no funcionó, mostrar mensaje final y eliminar el origen
	MsgBox "FALLO TOTAL después de 3 intentos:" & vbCrLf & _
	       "Archivo: " & nombreArchivo & vbCrLf & _
	       "El archivo origen será eliminado para evitar reprocesamiento.", _
	       vbCritical, "Error Crítico de Archivo"
	
	Err.Clear
	If FSO.FileExists(origen) Then
		FSO.DeleteFile origen, True
		If Err.Number <> 0 Then
			MsgBox "ERROR CRÍTICO: No se pudo eliminar el archivo origen:" & vbCrLf & _
			       "Archivo: " & nombreArchivo & vbCrLf & _
			       "Ruta: " & origen & vbCrLf & _
			       "Error: " & Err.Description & vbCrLf & vbCrLf & _
			       "ACCIÓN REQUERIDA: Eliminar manualmente este archivo.", _
			       vbCritical, "Error Crítico"
			Err.Clear
		End If
	End If
End Sub

sub msjRISnom
	large=Len(nombreid)
	for Each Fil in FLDtxt.Files
		if LCase(Left(FIL.Name,large))=nombreid Then
			'msgbox fil.Name
			nombretxt=FIL.Path	
			'msgbox nombretxt
			call MsjReader					
		end If		
	next 
end sub



sub MsjReader
	'msgbox fil.Name 
	set FSOtxt=CreateObject("Scripting.FileSystemObject")
	set ReadTXTmsj = FSOtxt.OpenTextFile(nombretxt)
	
	If Err.Number <> 0 Then
		MsgBox "ERROR al abrir archivo de texto:" & vbCrLf & _
		       "Archivo: " & FSO.GetFileName(nombretxt) & vbCrLf & _
		       "Ruta: " & nombretxt & vbCrLf & _
		       "Error: " & Err.Description, _
		       vbExclamation, "Error al Leer TXT"
		Err.Clear
		Exit Sub
	End If

	Do Until ReadTXTmsj.AtEndOfStream
		DCMrecord = ReadTXTmsj.readline()
		
		If InStr(DCMrecord, "(0040.0002)=") Then
			FechaProc=(Mid(DCMrecord,13,Len(DCMrecord)-12))
			'MsgBox FechaProc
		end if 
		
	loop
	
	ReadTXTmsj.Close
End sub


sub FindCadena
	Set InputFile = FSO.OpenTextFile(nombretxt)
	
	If Err.Number <> 0 Then
		MsgBox "ERROR al abrir archivo en FindCadena:" & vbCrLf & _
		       "Archivo: " & FSO.GetFileName(nombretxt) & vbCrLf & _
		       "Error: " & Err.Description, _
		       vbExclamation, "Error FindCadena"
		Err.Clear
		Exit Sub
	End If
	
	PDFfile=left(nombrefile,len(nombrefile)-4)&".pdf"

	Do until InputFile.AtEndOfStream
		tmpStr = InputFile.ReadLine
		substrtoFind="SOMATOM Scope"

		If InStr(tmpStr, substrToFind) <= 0 Then
		   'WScript.Echo "No matches"
		   
		Else
		   'msgbox mid(tmpStr,instrrev(tmpstr,substrtofind),+22)
		   idstring=mid(tmpStr,instrrev(tmpstr,substrtofind),+22)
		   id=right(idstring,8)
		   If isnumeric(id) Then
			nomdir=id
			'msgbox id
			if not fso.folderexists(StudyFolder & id) then
				fso.createfolder(StudyFolder & id)
				fso.copyfile pdffile, studyfolder&"\"&id&"\"
		    else
				'msgbox pdffile
				'msgbox studyfolder&id
				fso.copyfile pdffile, studyfolder&"\"&id&"\"
			end if
		    end if
		End If
		'fso.deletefile pdffile 
		'fso.deletefile nombrefile
	loop
	
	InputFile.Close
end sub

Set FLD = Nothing
Set FSO = Nothing
Set FLDtxt = Nothing


'TASKS
'msjRIStxt--->monitorea msjRIS en W2K12 ---> mueve .txt a D:\TJS\msrRIStxt\
'PDFin------->monitorea PDFs en W2k12 -----> mueve .pdf a D:\TJS\PDF2DCMin\
'PDF2PACS---->monitorea PDFs en D:\TJS\PDF2DCMin\ ------> ejectuta D:\TJS\CODE\PDF2PACS.VBS
'PDF2PACS.VBS:
