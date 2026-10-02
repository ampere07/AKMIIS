import React from 'react';
import { Camera, Trash2, Upload, ExternalLink } from 'lucide-react';
import { retryWithDriveExport } from '../../utils/applicationImages';

interface ImageUploadFieldProps {
    label: string;
    field: string;
    preview: string | null;
    isDarkMode: boolean;
    handleFileChange: (e: React.ChangeEvent<HTMLInputElement>, field: string) => void;
    clearFile: (field: string) => void;
}

const ImageUploadField = ({ label, field, preview, isDarkMode, handleFileChange, clearFile }: ImageUploadFieldProps) => (
    <div className="space-y-2">
        <label className={`text-sm font-medium ${isDarkMode ? 'text-gray-300' : 'text-gray-700'}`}>{label}</label>
        <div
            className={`relative group border-2 border-dashed rounded-xl overflow-hidden aspect-video flex flex-col items-center justify-center transition-all ${
                preview
                ? 'border-transparent'
                : (isDarkMode ? 'border-gray-700 hover:border-gray-500 bg-gray-800/50' : 'border-gray-300 hover:border-gray-400 bg-gray-50')
            }`}
        >
            {preview ? (
                <>
                    <img
                        src={preview}
                        alt={label}
                        className="w-full h-full object-cover"
                        onError={retryWithDriveExport}
                    />
                    <div className="absolute inset-0 bg-black/60 opacity-0 group-hover:opacity-100 transition-opacity flex flex-col items-center justify-center gap-3">
                        <div className="flex items-center gap-4">
                            <button
                                type="button"
                                onClick={() => window.open(preview)}
                                className="p-2.5 bg-white/20 hover:bg-white/40 text-white rounded-full transition-all backdrop-blur-sm"
                                title="View Image"
                            >
                                <ExternalLink size={18} />
                            </button>
                            <label className="p-2.5 bg-blue-500 hover:bg-blue-600 text-white rounded-full transition-all cursor-pointer shadow-lg" title="Replace Image">
                                <Upload size={18} />
                                <input type="file" aria-label={`Replace ${label}`} className="hidden" accept="image/*" onChange={(e) => handleFileChange(e, field)} />
                            </label>
                            <button
                                type="button"
                                onClick={() => clearFile(field)}
                                className="p-2.5 bg-red-500 hover:bg-red-600 text-white rounded-full transition-all"
                                title="Remove Image"
                            >
                                <Trash2 size={18} />
                            </button>
                        </div>
                        <span className="text-[10px] text-white/90 font-bold uppercase tracking-wider bg-black/20 px-2 py-0.5 rounded">Replace {label}</span>
                    </div>
                    <div className="absolute top-2 right-2 px-2 py-1 bg-green-500/90 text-white text-[10px] font-bold rounded shadow-lg uppercase tracking-wider backdrop-blur-sm">
                        Uploaded
                    </div>
                </>
            ) : (
                <>
                    <Camera size={32} className={isDarkMode ? 'text-gray-500' : 'text-gray-400'} />
                    <span className={`mt-2 text-xs font-medium ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`}>Click to upload {label}</span>
                    <input
                        type="file"
                        aria-label={`Upload ${label}`}
                        className="absolute inset-0 opacity-0 cursor-pointer"
                        accept="image/*"
                        onChange={(e) => handleFileChange(e, field)}
                    />
                </>
            )}
        </div>
    </div>
);

export default ImageUploadField;
